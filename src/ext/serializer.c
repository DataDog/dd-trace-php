#include <Zend/zend.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_interfaces.h>
#include <php.h>

#if PHP_VERSION_ID >= 70000
#include <Zend/zend_smart_str.h>
#endif

#include <ext/spl/spl_exceptions.h>

#include "arrays.h"
#include "compat_string.h"
#include "ddtrace.h"
#include "logging.h"
#include "mpack/mpack.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static int msgpack_write_zval(mpack_writer_t *writer, zval *trace TSRMLS_DC);

#if PHP_VERSION_ID < 70000
static int write_hash_table(mpack_writer_t *writer, HashTable *ht TSRMLS_DC) /* {{{ */
{
    zval **tmp;
    char *string_key;
    uint str_len;
    HashPosition iterator;
    zend_ulong num_key;
    int key_type;
    bool first_time = true;

    zend_hash_internal_pointer_reset_ex(ht, &iterator);
    while (zend_hash_get_current_data_ex(ht, (void **)&tmp, &iterator) == SUCCESS) {
        key_type = zend_hash_get_current_key_ex(ht, &string_key, &str_len, &num_key, 0, &iterator);
        if (first_time == true) {
            first_time = false;
            if (key_type == HASH_KEY_IS_STRING) {
                mpack_start_map(writer, zend_hash_num_elements(ht));
            } else {
                mpack_start_array(writer, zend_hash_num_elements(ht));
            }
        }
        if (key_type == HASH_KEY_IS_STRING) {
            mpack_write_cstr(writer, string_key);
        }
        if (msgpack_write_zval(writer, *tmp TSRMLS_CC) != 1) {
            return 0;
        }
        zend_hash_move_forward_ex(ht, &iterator);
    }

    if (key_type == HASH_KEY_IS_STRING) {
        mpack_finish_map(writer);
    } else {
        mpack_finish_array(writer);
    }
    return 1;
}
#else
static int write_hash_table(mpack_writer_t *writer, HashTable *ht TSRMLS_DC) /* {{{ */
{
    zval *tmp;
    zend_string *string_key;
    int is_assoc = -1;

    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(ht, string_key, tmp) {
        if (is_assoc == -1) {
            is_assoc = string_key != NULL ? 1 : 0;
            if (is_assoc == 1) {
                mpack_start_map(writer, zend_hash_num_elements(ht));
            } else {
                mpack_start_array(writer, zend_hash_num_elements(ht));
            }
        }
        if (is_assoc == 1) {
            mpack_write_cstr(writer, ZSTR_VAL(string_key));
        }
        if (msgpack_write_zval(writer, tmp TSRMLS_CC) != 1) {
            return 0;
        }
    }
    ZEND_HASH_FOREACH_END();

    if (is_assoc) {
        mpack_finish_map(writer);
    } else {
        mpack_finish_array(writer);
    }
    return 1;
}
#endif

static int msgpack_write_zval(mpack_writer_t *writer, zval *trace TSRMLS_DC) /* {{{ */
{
#if PHP_VERSION_ID >= 70000
    if (Z_TYPE_P(trace) == IS_REFERENCE) {
        trace = Z_REFVAL_P(trace);
    }
#endif
    switch (Z_TYPE_P(trace)) {
        case IS_ARRAY:
            if (write_hash_table(writer, Z_ARRVAL_P(trace) TSRMLS_CC) != 1) {
                return 0;
            }
            break;
        case IS_DOUBLE:
            mpack_write_double(writer, Z_DVAL_P(trace));
            break;
        case IS_LONG:
            mpack_write_int(writer, Z_LVAL_P(trace));
            break;
        case IS_NULL:
            mpack_write_nil(writer);
            break;
#if PHP_VERSION_ID < 70000
        case IS_BOOL:
            mpack_write_bool(writer, Z_BVAL_P(trace) == 1);
            break;
        case IS_STRING:
            mpack_write_cstr(writer, Z_STRVAL_P(trace));
            break;
#else
        case IS_TRUE:
        case IS_FALSE:
            mpack_write_bool(writer, Z_TYPE_P(trace) == IS_TRUE);
            break;
        case IS_STRING:
            mpack_write_cstr(writer, ZSTR_VAL(Z_STR_P(trace)));
            break;
#endif
        default:
            ddtrace_log_debug("Serialize values must be of type array, string, int, float, bool or null");
            return 0;
            break;
    }
    return 1;
}

int ddtrace_serialize_simple_array_into_c_string(zval *trace, char **data_p, size_t *size_p TSRMLS_DC) {
    // encode to memory buffer
    char *data;
    size_t size;
    mpack_writer_t writer;
    mpack_writer_init_growable(&writer, &data, &size);
    if (msgpack_write_zval(&writer, trace TSRMLS_CC) != 1) {
        mpack_writer_destroy(&writer);
        free(data);
        return 0;
    }
    // finish writing
    if (mpack_writer_destroy(&writer) != mpack_ok) {
        free(data);
        return 0;
    }

    if (data_p && size_p) {
        *data_p = data;
        *size_p = size;

        return 1;
    } else {
        return 0;
    }
}

int ddtrace_serialize_simple_array(zval *trace, zval *retval TSRMLS_DC) {
    // encode to memory buffer
    char *data;
    size_t size;

    if (ddtrace_serialize_simple_array_into_c_string(trace, &data, &size TSRMLS_CC)) {
#if PHP_VERSION_ID < 70000
        ZVAL_STRINGL(retval, data, size, 1);
#else
        ZVAL_STRINGL(retval, data, size);
#endif
        free(data);
        return 1;
    } else {
        return 0;
    }
}

#if PHP_VERSION_ID < 70000
static zval *_read_span_property(zval *span_data, const char *name, size_t name_len TSRMLS_DC) {
    return zend_read_property(ddtrace_ce_span_data, span_data, name, name_len, 1 TSRMLS_CC);
}

static void _add_assoc_zval_copy(zval *el, const char *name, zval *prop) {
    zval *value;
    ALLOC_ZVAL(value);
    INIT_PZVAL_COPY(value, prop);
    zval_copy_ctor(value);
    add_assoc_zval(el, name, value);
}

/* gettraceasstring() macros
 * @see https://github.com/php/php-src/blob/PHP-5.4/Zend/zend_exceptions.c#L364-L395
 * {{{ */
#define _DD_TRACE_APPEND_STRL(val, vallen)           \
    {                                                \
        int l = vallen;                              \
        *str = (char *)erealloc(*str, *len + l + 1); \
        memcpy((*str) + *len, val, l);               \
        *len += l;                                   \
    }

#define _DD_TRACE_APPEND_STR(val) _DD_TRACE_APPEND_STRL(val, sizeof(val) - 1)

#define _DD_TRACE_APPEND_KEY(key)                                          \
    if (zend_hash_find(ht, key, sizeof(key), (void **)&tmp) == SUCCESS) {  \
        if (Z_TYPE_PP(tmp) != IS_STRING) {                                 \
            /* zend_error(E_WARNING, "Value for %s is no string", key); */ \
            _DD_TRACE_APPEND_STR("[unknown]");                             \
        } else {                                                           \
            _DD_TRACE_APPEND_STRL(Z_STRVAL_PP(tmp), Z_STRLEN_PP(tmp));     \
        }                                                                  \
    }
/* }}} */

/* This is modelled after _build_trace_string in PHP 5.4
 * @see https://github.com/php/php-src/blob/PHP-5.4/Zend/zend_exceptions.c#L543-L605
 */
static int _trace_string(zval **frame TSRMLS_DC, int num_args, va_list args, zend_hash_key *hash_key) {
    PHP5_UNUSED(hash_key);
#ifdef ZTS
    /* This arg is required for the zend_hash_apply_with_arguments function signature, but we don't need it */
    PHP5_UNUSED(TSRMLS_C);
#endif

    char *s_tmp, **str;
    int *len, *num;
    long line;
    HashTable *ht = Z_ARRVAL_PP(frame);
    zval **file, **tmp;

    if (Z_TYPE_PP(frame) != IS_ARRAY || num_args != 3) {
        return ZEND_HASH_APPLY_KEEP;
    }

    str = va_arg(args, char **);
    len = va_arg(args, int *);
    num = va_arg(args, int *);

    s_tmp = emalloc(1 + MAX_LENGTH_OF_LONG + 1 + 1);
    sprintf(s_tmp, "#%d ", (*num)++);
    _DD_TRACE_APPEND_STRL(s_tmp, strlen(s_tmp));
    efree(s_tmp);
    if (zend_hash_find(ht, "file", sizeof("file"), (void **)&file) == SUCCESS) {
        if (Z_TYPE_PP(file) != IS_STRING) {
            ddtrace_log_debug("serializer stack trace: Function name is not a string");
            _DD_TRACE_APPEND_STR("[unknown function]");
        } else {
            if (zend_hash_find(ht, "line", sizeof("line"), (void **)&tmp) == SUCCESS) {
                if (Z_TYPE_PP(tmp) == IS_LONG) {
                    line = Z_LVAL_PP(tmp);
                } else {
                    ddtrace_log_debug("serializer stack trace: Line is not a long");
                    line = 0;
                }
            } else {
                line = 0;
            }
            s_tmp = emalloc(Z_STRLEN_PP(file) + MAX_LENGTH_OF_LONG + 4 + 1);
            sprintf(s_tmp, "%s(%ld): ", Z_STRVAL_PP(file), line);
            _DD_TRACE_APPEND_STRL(s_tmp, strlen(s_tmp));
            efree(s_tmp);
        }
    } else {
        _DD_TRACE_APPEND_STR("[internal function]: ");
    }

    _DD_TRACE_APPEND_KEY("class");
    _DD_TRACE_APPEND_KEY("type");
    _DD_TRACE_APPEND_KEY("function");

    if (zend_hash_find(ht, "args", sizeof("args"), (void **)&tmp) == SUCCESS && Z_TYPE_PP(tmp) == IS_ARRAY &&
        zend_hash_num_elements(Z_ARRVAL_PP(tmp))) {
        _DD_TRACE_APPEND_STR("(...)\n");
    } else {
        _DD_TRACE_APPEND_STR("()\n");
    }

    return ZEND_HASH_APPLY_KEEP;
}

/* This is modelled after getTraceAsString in PHP 5.4
 * @see https://github.com/php/php-src/blob/PHP-5.4/Zend/zend_exceptions.c#L607-L635
 */
static void _serialize_stack_trace(zval *meta, zval *trace TSRMLS_DC) {
    char *res, **str, *s_tmp;
    int res_len = 0, *len = &res_len, num = 0;

    res = estrdup("");
    str = &res;

    zend_hash_apply_with_arguments(Z_ARRVAL_P(trace) TSRMLS_CC, (apply_func_args_t)_trace_string, 3, str, len, &num);

    s_tmp = emalloc(1 + MAX_LENGTH_OF_LONG + 7 + 1);
    sprintf(s_tmp, "#%d {main}", num);
    _DD_TRACE_APPEND_STRL(s_tmp, strlen(s_tmp));
    efree(s_tmp);

    res[res_len] = '\0';

    add_assoc_string(meta, "error.stack", res, 0);
}

static void _serialize_exception(zval *el, zval *meta, ddtrace_span_t *span TSRMLS_DC) {
    zend_uint class_name_len;
    const char *class_name;
    zval *exception = span->exception, *msg = NULL, *stack = NULL;

    if (!exception) {
        return;
    }

    int needs_copied = zend_get_object_classname(exception, &class_name, &class_name_len TSRMLS_CC);

    add_assoc_long(el, "error", 1);

    zend_call_method_with_0_params(&exception, Z_OBJCE_P(exception), NULL, "getmessage", &msg);

    /* add_assoc_stringl does not actually mutate the string, but we've either
     * already made a copy, or it will when it duplicates with dup param, so
     * if it did it should still be safe. */
    add_assoc_stringl(meta, "error.type", (char *)class_name, class_name_len, needs_copied);
    add_assoc_zval(meta, "error.msg", msg);

    /* Note, we use Exception::getTrace() instead of getTraceAsString because
     * function arguments can contain sensitive information. Since we do not
     * have a comprehensive way to know which function arguments are sensitive
     * we will just hide all of them. */
    zend_call_method_with_0_params(&exception, Z_OBJCE_P(exception), NULL, "gettrace", &stack);
    _serialize_stack_trace(meta, stack TSRMLS_CC);
    zval_ptr_dtor(&stack);
}

static void _serialize_meta(zval *el, ddtrace_span_t *span TSRMLS_DC) {
    zval *meta, *orig_meta = _read_span_property(span->span_data, ZEND_STRL("meta") TSRMLS_CC);
    ALLOC_INIT_ZVAL(meta);
    array_init(meta);
    if (orig_meta && Z_TYPE_P(orig_meta) == IS_ARRAY) {
        int key_type;
        zval **orig_val;
        zval *val_as_string;
        HashPosition pos;
        char *str_key;
        uint str_key_len;
        ulong num_key;
        zend_hash_internal_pointer_reset_ex(Z_ARRVAL_P(orig_meta), &pos);
        while (zend_hash_get_current_data_ex(Z_ARRVAL_P(orig_meta), (void **)&orig_val, &pos) == SUCCESS) {
            key_type = zend_hash_get_current_key_ex(Z_ARRVAL_P(orig_meta), &str_key, &str_key_len, &num_key, 0, &pos);
            if (key_type == HASH_KEY_IS_STRING) {
                ALLOC_INIT_ZVAL(val_as_string);
                ddtrace_convert_to_string(val_as_string, *orig_val TSRMLS_CC);
                add_assoc_zval_ex(meta, str_key, str_key_len, val_as_string);
            }
            zend_hash_move_forward_ex(Z_ARRVAL_P(orig_meta), &pos);
        }
    }

    _serialize_exception(el, meta, span TSRMLS_CC);
    // zend_hash_exists on PHP 5 needs `sizeof(string)`, not `sizeof(string) - 1`
    if (!span->exception && zend_hash_exists(Z_ARRVAL_P(meta), "error.msg", sizeof("error.msg"))) {
        add_assoc_long(el, "error", 1);
    }
    if (span->parent_id == 0) {
        char pid[MAX_LENGTH_OF_LONG + 1];
        snprintf(pid, sizeof(pid), "%ld", (long)span->pid);
        add_assoc_string(meta, "system.pid", pid, 1);
    }

    // Add meta only if it has elements
    if (zend_hash_num_elements(Z_ARRVAL_P(meta))) {
        add_assoc_zval(el, "meta", meta);
    } else {
        zval_dtor(meta);
        efree(meta);
    }
}

#define ADD_ELEMENT_IF_NOT_NULL(name)                                                        \
    do {                                                                                     \
        zval *prop = _read_span_property(span->span_data, name, sizeof(name) - 1 TSRMLS_CC); \
        zval *prop_as_string;                                                                \
        if (Z_TYPE_P(prop) != IS_NULL) {                                                     \
            ALLOC_INIT_ZVAL(prop_as_string);                                                 \
            ddtrace_convert_to_string(prop_as_string, prop TSRMLS_CC);                       \
            add_assoc_zval(el, name, prop_as_string);                                        \
        }                                                                                    \
    } while (0);

#else
static zval *_read_span_property(zval *span_data, const char *name, size_t name_len) {
    zval rv;
    return zend_read_property(ddtrace_ce_span_data, span_data, name, name_len, 1, &rv);
}

static void _add_assoc_zval_copy(zval *el, const char *name, zval *prop) {
    zval value;
    ZVAL_COPY(&value, prop);
    add_assoc_zval(el, (name), &value);
}

/* _DD_TRACE_APPEND_KEY is not exported */
#define _DD_TRACE_APPEND_KEY(key)                                               \
    do {                                                                        \
        tmp = zend_hash_str_find(ht, key, sizeof(key) - 1);                     \
        if (tmp) {                                                              \
            if (Z_TYPE_P(tmp) != IS_STRING) {                                   \
                /* zend_error(E_WARNING, "Value for %s is not string", key); */ \
                smart_str_appends(str, "[unknown]");                            \
            } else {                                                            \
                smart_str_appends(str, Z_STRVAL_P(tmp));                        \
            }                                                                   \
        }                                                                       \
    } while (0)

/* This is modelled after _build_trace_string in PHP 7.0:
 * @see https://github.com/php/php-src/blob/PHP-7.0/Zend/zend_exceptions.c#L581-L638
 */
static void _trace_string(smart_str *str, HashTable *ht, uint32_t num) /* {{{ */
{
    zval *file, *tmp;

    smart_str_appendc(str, '#');
    smart_str_append_long(str, num);
    smart_str_appendc(str, ' ');

    file = zend_hash_str_find(ht, ZEND_STRL("file"));
    if (file) {
        if (Z_TYPE_P(file) != IS_STRING) {
            ddtrace_log_debug("serializer stack trace: Function name is not a string");
            smart_str_appends(str, "[unknown function]");
        } else {
            zend_long line;
            tmp = zend_hash_str_find(ht, "line", sizeof("line") - 1);
            if (tmp) {
                if (Z_TYPE_P(tmp) == IS_LONG) {
                    line = Z_LVAL_P(tmp);
                } else {
                    ddtrace_log_debug("serializer stack trace: Line is not a long");
                    line = 0;
                }
            } else {
                line = 0;
            }
            smart_str_append(str, Z_STR_P(file));
            smart_str_appendc(str, '(');
            smart_str_append_long(str, line);
            smart_str_appends(str, "): ");
        }
    } else {
        smart_str_appends(str, "[internal function]: ");
    }
    _DD_TRACE_APPEND_KEY("class");
    _DD_TRACE_APPEND_KEY("type");
    _DD_TRACE_APPEND_KEY("function");
    tmp = zend_hash_str_find(ht, "args", sizeof("args") - 1);

    /* If there were arguments, show an ellipsis, otherwise nothing */
    if (tmp && Z_TYPE_P(tmp) == IS_ARRAY && zend_hash_num_elements(Z_ARRVAL_P(tmp))) {
        smart_str_appends(str, "(...)\n");
    } else {
        smart_str_appends(str, "()\n");
    }
}

/* Modelled after getTraceAsString from PHP 5.4:
 * @see https://lxr.room11.org/xref/php-src%405.4/Zend/zend_exceptions.c#609-635
 */
static void _serialize_stack_trace(zval *meta, zval *trace) {
    zval *frame, output;
    smart_str str = {0};
    uint32_t num = 0;

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(trace), frame) {
        if (Z_TYPE_P(frame) != IS_ARRAY) {
            /* zend_error(E_WARNING, "Expected array for frame %" ZEND_ULONG_FMT_SPEC, index); */
            continue;
        }

        _trace_string(&str, Z_ARRVAL_P(frame), num++);
    }
    ZEND_HASH_FOREACH_END();

    smart_str_appendc(&str, '#');
    smart_str_append_long(&str, num);
    smart_str_appends(&str, " {main}");
    smart_str_0(&str);

    ZVAL_NEW_STR(&output, str.s);

    add_assoc_zval(meta, "error.stack", &output);
}

static void _serialize_exception(zval *el, zval *meta, ddtrace_span_t *span) {
    zval exception, name, msg, stack;
    if (!span->exception) {
        return;
    }

    ZVAL_OBJ(&exception, span->exception);

    add_assoc_long(el, "error", 1);

    ZVAL_STR(&name, Z_OBJCE(exception)->name);
    zend_call_method_with_0_params(&exception, Z_OBJCE(exception), NULL, "getmessage", &msg);

    _add_assoc_zval_copy(meta, "error.type", &name);
    add_assoc_zval(meta, "error.msg", &msg);

    /* Note, we use Exception::getTrace() instead of getTraceAsString because
     * function arguments can contain sensitive information. Since we do not
     * have a comprehensive way to know which function arguments are sensitive
     * we will just hide all of them. */
    zend_call_method_with_0_params(&exception, Z_OBJCE(exception), NULL, "gettrace", &stack);
    _serialize_stack_trace(meta, &stack);
    zval_ptr_dtor(&stack);
}

static void _serialize_meta(zval *el, ddtrace_span_t *span) {
    zval meta_zv, *meta = _read_span_property(span->span_data, "meta", sizeof("meta") - 1);

    array_init(&meta_zv);
    if (meta && Z_TYPE_P(meta) == IS_ARRAY) {
        zend_string *str_key;
        zval *orig_val, val_as_string;
        ZEND_HASH_FOREACH_STR_KEY_VAL_IND(Z_ARRVAL_P(meta), str_key, orig_val) {
            if (str_key) {
                ddtrace_convert_to_string(&val_as_string, orig_val);
                add_assoc_zval(&meta_zv, ZSTR_VAL(str_key), &val_as_string);
            }
        }
        ZEND_HASH_FOREACH_END();
    }
    meta = &meta_zv;

    _serialize_exception(el, meta, span);
    if (!span->exception) {
        zval *error = ddtrace_hash_find_ptr(Z_ARR_P(meta), ZEND_STRL("error.msg"));
        if (error) {
            add_assoc_long(el, "error", 1);
        }
    }
    if (span->parent_id == 0) {
        char pid[MAX_LENGTH_OF_LONG + 1];
        snprintf(pid, sizeof(pid), "%ld", (long)span->pid);
        add_assoc_string(meta, "system.pid", pid);
    }

    if (zend_array_count(Z_ARRVAL_P(meta))) {
        add_assoc_zval(el, "meta", meta);
    } else {
        zval_ptr_dtor(meta);
    }
}

#define ADD_ELEMENT_IF_NOT_NULL(name)                                                        \
    do {                                                                                     \
        zval *prop = _read_span_property(span->span_data, name, sizeof(name) - 1 TSRMLS_CC); \
        zval prop_as_string;                                                                 \
        if (Z_TYPE_P(prop) != IS_NULL) {                                                     \
            ddtrace_convert_to_string(&prop_as_string, prop);                                \
            _add_assoc_zval_copy(el, name, &prop_as_string);                                 \
            zval_dtor(&prop_as_string);                                                      \
        }                                                                                    \
    } while (0);
#endif

void ddtrace_serialize_span_to_array(ddtrace_span_t *span, zval *array TSRMLS_DC) {
    zval *el;
#if PHP_VERSION_ID < 70000
    ALLOC_INIT_ZVAL(el);
#else
    zval zv;
    el = &zv;
#endif
    array_init(el);

    add_assoc_long(el, "trace_id", span->trace_id);
    add_assoc_long(el, "span_id", span->span_id);
    if (span->parent_id > 0) {
        add_assoc_long(el, "parent_id", span->parent_id);
    }
    add_assoc_long(el, "start", span->start);
    add_assoc_long(el, "duration", span->duration);

    ADD_ELEMENT_IF_NOT_NULL("name");
    ADD_ELEMENT_IF_NOT_NULL("resource");
    ADD_ELEMENT_IF_NOT_NULL("service");
    ADD_ELEMENT_IF_NOT_NULL("type");

    _serialize_meta(el, span TSRMLS_CC);

    zval *metrics = _read_span_property(span->span_data, ZEND_STRL("metrics") TSRMLS_CC);
    if (Z_TYPE_P(metrics) == IS_ARRAY) {
        _add_assoc_zval_copy(el, "metrics", metrics);
    }

    add_next_index_zval(array, el);
}
