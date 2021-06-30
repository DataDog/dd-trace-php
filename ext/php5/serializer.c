#include <Zend/zend.h>
#include <Zend/zend_builtin_functions.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_interfaces.h>
#include <exceptions/exceptions.h>
#include <inttypes.h>
#include <php.h>
#include <properties/properties.h>
#include <stdbool.h>
#include <stdlib.h>
#include <string.h>

#include "compat_string.h"
#include "compatibility.h"
#include "ddtrace.h"
#include "engine_hooks.h"
#include "logging.h"
#include "mpack/mpack.h"
#include "span.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#define MAX_ID_BUFSIZ 21  // 1.8e^19 = 20 chars + 1 terminator
#define KEY_TRACE_ID "trace_id"
#define KEY_SPAN_ID "span_id"
#define KEY_PARENT_ID "parent_id"

static int msgpack_write_zval(mpack_writer_t *writer, zval *trace TSRMLS_DC);

static int write_hash_table(mpack_writer_t *writer, HashTable *ht TSRMLS_DC) {
    zval **tmp;
    char *string_key;
    uint str_len;
    HashPosition iterator;
    zend_ulong num_key;
    int key_type = HASH_KEY_NON_EXISTANT;
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

        // Writing the key, if associative
        bool zval_string_as_uint64 = false;
        if (key_type == HASH_KEY_IS_STRING) {
            mpack_write_cstr(writer, string_key);
            // If the key is trace_id, span_id or parent_id then strings have to be converted to uint64 when packed.
            if (0 == strcmp(KEY_TRACE_ID, string_key) || 0 == strcmp(KEY_SPAN_ID, string_key) ||
                0 == strcmp(KEY_PARENT_ID, string_key)) {
                zval_string_as_uint64 = true;
            }
        }

        // Writing the value
        if (zval_string_as_uint64) {
            mpack_write_u64(writer, strtoull(Z_STRVAL_PP(tmp), NULL, 10));
        } else if (msgpack_write_zval(writer, *tmp TSRMLS_CC) != 1) {
            return 0;
        }

        zend_hash_move_forward_ex(ht, &iterator);
    }

    if (key_type == HASH_KEY_NON_EXISTANT) {
        mpack_start_array(writer, 0);
        mpack_finish_array(writer);
    } else if (key_type == HASH_KEY_IS_STRING) {
        mpack_finish_map(writer);
    } else {
        mpack_finish_array(writer);
    }
    return 1;
}

static int msgpack_write_zval(mpack_writer_t *writer, zval *trace TSRMLS_DC) {
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
        case IS_BOOL:
            mpack_write_bool(writer, Z_BVAL_P(trace) == 1);
            break;
        case IS_STRING:
            mpack_write_cstr(writer, Z_STRVAL_P(trace));
            break;
        default:
            ddtrace_log_debug("Serialize values must be of type array, string, int, float, bool or null");
            return 0;
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
        ZVAL_STRINGL(retval, data, size, 1);
        free(data);
        return 1;
    } else {
        return 0;
    }
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

/* This is modeled after _build_trace_string in PHP 5.4
 * @see https://github.com/php/php-src/blob/PHP-5.4/Zend/zend_exceptions.c#L543-L605
 */
static int _trace_string(zval **frame TSRMLS_DC, int num_args, va_list args, zend_hash_key *hash_key) {
    UNUSED(hash_key);
#ifdef ZTS
    /* This arg is required for the zend_hash_apply_with_arguments function signature, but we don't need it */
    UNUSED(TSRMLS_C);
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

    /* We intentionally do not show any arguments, not even an ellipsis if there
     * are arguments; this is for consistency with PHP 7 where there is an INI
     * setting called zend.exception_ignore_args that prevents "args" from being
     * generated.
     */
    _DD_TRACE_APPEND_STR("()\n");

    return ZEND_HASH_APPLY_KEEP;
}

/* This is modeled after Exception::getTraceAsString() in PHP 5.4
 * @see https://github.com/php/php-src/blob/PHP-5.4/Zend/zend_exceptions.c#L607-L635
 */
static char *dd_serialize_stack_trace(zval *trace TSRMLS_DC) {
    char *res, **str, *s_tmp;
    int res_len = 0, *len = &res_len, num = 0;

    if (Z_TYPE_P(trace) != IS_ARRAY) {
        return NULL;
    }

    res = estrdup("");
    str = &res;

    zend_hash_apply_with_arguments(Z_ARRVAL_P(trace) TSRMLS_CC, (apply_func_args_t)_trace_string, 3, str, len, &num);

    s_tmp = emalloc(1 + MAX_LENGTH_OF_LONG + 7 + 1);
    sprintf(s_tmp, "#%d {main}", num);
    _DD_TRACE_APPEND_STRL(s_tmp, strlen(s_tmp));
    efree(s_tmp);

    res[res_len] = '\0';

    return res;
}

typedef int (*add_tag_fn_t)(void *context, zai_string_view key, zai_string_view value);

static int dd_exception_to_error_msg(zval *exception, void *context, add_tag_fn_t add_tag TSRMLS_DC) {
    zai_string_view key = ZAI_STRL_VIEW("error.msg");
    zai_string_view msg = zai_exception_message(exception TSRMLS_CC);
    return add_tag(context, key, msg);
}

static int dd_exception_to_error_type(zval *exception, void *context, add_tag_fn_t add_tag TSRMLS_DC) {
    zai_string_view value = ZAI_STRL_VIEW("{unknown error}"), key = ZAI_STRL_VIEW("error.type");

    if (instanceof_function(Z_OBJCE_P(exception), ddtrace_ce_fatal_error TSRMLS_CC)) {
        zval *code = ZAI_EXCEPTION_PROPERTY(exception, "code");

        if (Z_TYPE_P(code) == IS_LONG) {
            switch (Z_LVAL_P(code)) {
                case E_ERROR:
                    value = ZAI_STRL_VIEW("E_ERROR");
                    break;
                case E_CORE_ERROR:
                    value = ZAI_STRL_VIEW("E_CORE_ERROR");
                    break;
                case E_COMPILE_ERROR:
                    value = ZAI_STRL_VIEW("E_COMPILE_ERROR");
                    break;
                case E_USER_ERROR:
                    value = ZAI_STRL_VIEW("E_USER_ERROR");
                    break;
                default:
                    ddtrace_assert_log_debug(
                        "Unhandled error type in DDTrace\\FatalError; is a fatal error case missing?");
            }

        } else {
            ddtrace_assert_log_debug("Exception was a DDTrace\\FatalError but failed to get an exception code");
        }
    } else {
        value = (zai_string_view){.ptr = Z_OBJCE_P(exception)->name, .len = strlen(Z_OBJCE_P(exception)->name)};
    }

    return add_tag(context, key, value);
}

static int dd_exception_to_error_stack(zval *exception, void *context, add_tag_fn_t add_tag TSRMLS_DC) {
    smart_str trace_string = zai_get_trace_without_args_from_exception(exception TSRMLS_CC);
    zai_string_view key = ZAI_STRL_VIEW("error.stack");
    zai_string_view value = {.ptr = trace_string.c, .len = trace_string.len};
    int result = add_tag(context, key, value);
    efree(trace_string.c);
    return result;
}

int ddtrace_exception_to_meta(zval *exception, void *context, add_tag_fn_t add_meta TSRMLS_DC) {
    bool success = dd_exception_to_error_msg(exception, context, add_meta TSRMLS_CC) == SUCCESS &&
                   dd_exception_to_error_type(exception, context, add_meta TSRMLS_CC) == SUCCESS &&
                   dd_exception_to_error_stack(exception, context, add_meta TSRMLS_CC) == SUCCESS;
    return success ? SUCCESS : FAILURE;
}

static zval *dd_fatal_error_type(int code) {
    zval *type = NULL;
    const char *error_type = "{unknown error}";
    switch (code) {
        case E_ERROR:
            error_type = "E_ERROR";
            break;
        case E_CORE_ERROR:
            error_type = "E_CORE_ERROR";
            break;
        case E_COMPILE_ERROR:
            error_type = "E_COMPILE_ERROR";
            break;
        case E_USER_ERROR:
            error_type = "E_USER_ERROR";
            break;
    }
    MAKE_STD_ZVAL(type);
    ZVAL_STRING(type, error_type, 1);
    return type;
}

static zval *dd_fatal_error_msg(const char *format, va_list args) {
    zval *msg = NULL;
    va_list args2;
    char *buffer;

    va_copy(args2, args);
    int buffer_len = vspprintf(&buffer, 0, format, args2);
    va_end(args2);

    MAKE_STD_ZVAL(msg);
    if (buffer_len <= 0) {
        ZVAL_STRING(msg, "Unknown error", 1);
        efree(buffer);
        return msg;
    }

    /* In PHP 5 an uncaught exception results in a fatal error. The error
     * message includes the call stack and its arguments, which is a vector for
     * information disclosure. We need to avoid this.
     */
    const char uncaught[] = "Uncaught ";
    /* The -1 is to avoid the null terminator which is included in literals and
     * will not be present in the rendered error message at that position.
     */
    size_t uncaught_len = sizeof uncaught - 1;
    if ((unsigned)buffer_len >= uncaught_len && memcmp(buffer, uncaught, uncaught_len) == 0) {
        char *newline = memchr(buffer, '\n', buffer_len);
        if (newline) {
            *newline = '\0';
            size_t linelen = newline - buffer;
            // +1 for null terminator
            ZVAL_STRINGL(msg, buffer, linelen + 1, 1);
        } else {
            // This is suspect; there is always a newline in these messages
            ZVAL_STRING(msg, "Unknown uncaught exception", 1);
        }
    } else {
        ZVAL_STRING(msg, buffer, 1);
    }
    efree(buffer);
    return msg;
}

static zval *dd_fatal_error_stack(void) {
    zval *stack = NULL;
    zval *trace;
    TSRMLS_FETCH();

    MAKE_STD_ZVAL(trace);
    zend_fetch_debug_backtrace(trace, 0, DEBUG_BACKTRACE_IGNORE_ARGS, 0 TSRMLS_CC);
    if (Z_TYPE_P(trace) == IS_ARRAY) {
        char *stack_str = dd_serialize_stack_trace(trace TSRMLS_CC);
        if (stack_str) {
            MAKE_STD_ZVAL(stack);
            ZVAL_STRING(stack, stack_str, 0);
        }
    }
    zval_ptr_dtor(&trace);
    return stack;
}

typedef struct dd_error_info {
    zval *type;
    zval *msg;
    zval *stack;
} dd_error_info;

static void dd_fatal_error_to_meta(zval *meta, dd_error_info error) {
    if (error.type) {
        Z_ADDREF_P(error.type);
        add_assoc_zval(meta, "error.type", error.type);
    }
    if (error.msg) {
        Z_ADDREF_P(error.msg);
        add_assoc_zval(meta, "error.msg", error.msg);
    }
    if (error.stack) {
        Z_ADDREF_P(error.stack);
        add_assoc_zval(meta, "error.stack", error.stack);
    }
}

static int dd_add_meta_array(void *meta, zai_string_view key, zai_string_view value) {
    add_assoc_stringl_ex((zval *)meta, key.ptr, key.len, (char *)value.ptr, value.len, 1);
    return SUCCESS;
}

static void _serialize_meta(zval *el, ddtrace_span_fci *span_fci TSRMLS_DC) {
    ddtrace_span_t *span = &span_fci->span;
    bool top_level_span = span->parent_id == DDTRACE_G(distributed_parent_trace_id);
    zval *meta, *orig_meta = ddtrace_spandata_property_meta(span);
    ALLOC_INIT_ZVAL(meta);
    array_init(meta);

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

    if (span_fci->exception) {
        ddtrace_exception_to_meta(span_fci->exception, meta, dd_add_meta_array TSRMLS_CC);
    }

    zend_bool error = zend_hash_exists(Z_ARRVAL_P(meta), "error.msg", sizeof("error.msg")) ||
                      zend_hash_exists(Z_ARRVAL_P(meta), "error.type", sizeof("error.type"));
    if (error) {
        add_assoc_long(el, "error", 1);
    }

    if (top_level_span) {
        char pid[MAX_LENGTH_OF_LONG + 1];
        snprintf(pid, sizeof(pid), "%ld", (long)getpid());
        add_assoc_string(meta, "system.pid", pid, 1);
    }

    zai_string_view version = get_DD_VERSION();
    if (version.len > 0) {  // non-empty
        add_assoc_stringl(meta, "version", (char *)version.ptr, version.len, 1);
    }

    zai_string_view env = get_DD_ENV();
    if (env.len > 0) {  // non-empty
        add_assoc_stringl(meta, "env", (char *)env.ptr, env.len, 1);
    }

    HashTable *global_tags = get_DD_TAGS();
    char *key;
    uint key_len;
    ulong tag_num_key;
    zval **val;
    HashPosition tag_pos;

    for (zend_hash_internal_pointer_reset_ex(global_tags, &tag_pos);
         zend_hash_get_current_key_ex(global_tags, &key, &key_len, &tag_num_key, 0, &tag_pos),
         zend_hash_get_current_data_ex(global_tags, (void **)&val, &tag_pos) == SUCCESS;
         zend_hash_move_forward_ex(global_tags, &tag_pos)) {
        if (zend_hash_add(Z_ARRVAL_P(meta), key, key_len, (void **)val, sizeof(zval *), NULL) == SUCCESS) {
            zval_addref_p(*val);
        }
    }

    for (zend_hash_internal_pointer_reset_ex(&DDTRACE_G(additional_global_tags), &tag_pos);
         zend_hash_get_current_key_ex(&DDTRACE_G(additional_global_tags), &key, &key_len, &tag_num_key, 0, &tag_pos),
         zend_hash_get_current_data_ex(&DDTRACE_G(additional_global_tags), (void **)&val, &tag_pos) == SUCCESS;
         zend_hash_move_forward_ex(&DDTRACE_G(additional_global_tags), &tag_pos)) {
        if (zend_hash_add(Z_ARRVAL_P(meta), key, key_len, (void **)val, sizeof(zval *), NULL) == SUCCESS) {
            zval_addref_p(*val);
        }
    }

    // Add meta only if it has elements
    if (zend_hash_num_elements(Z_ARRVAL_P(meta))) {
        add_assoc_zval(el, "meta", meta);
    } else {
        zval_dtor(meta);
        efree(meta);
    }
}

void ddtrace_serialize_span_to_array(ddtrace_span_fci *span_fci, zval *array TSRMLS_DC) {
    ddtrace_span_t *span = &span_fci->span;
    bool top_level_span = span->parent_id == DDTRACE_G(distributed_parent_trace_id);
    zval *el;
    ALLOC_INIT_ZVAL(el);
    array_init(el);

    char trace_id_str[MAX_ID_BUFSIZ];
    sprintf(trace_id_str, "%" PRIu64, span->trace_id);
    add_assoc_string(el, KEY_TRACE_ID, trace_id_str, /* duplicate */ 1);

    char span_id_str[MAX_ID_BUFSIZ];
    sprintf(span_id_str, "%" PRIu64, span->span_id);
    add_assoc_string(el, KEY_SPAN_ID, span_id_str, /* duplicate */ 1);

    if (span->parent_id > 0) {
        char parent_id_str[MAX_ID_BUFSIZ];
        sprintf(parent_id_str, "%" PRIu64, span->parent_id);
        add_assoc_string(el, KEY_PARENT_ID, parent_id_str, /* duplicate */ 1);
    }
    add_assoc_long(el, "start", span->start);
    add_assoc_long(el, "duration", span->duration);

    // SpanData::$name defaults to fully qualified called name (set at span close)
    zval *prop_name = ddtrace_spandata_property_name(span);
    zval *prop_name_as_string = NULL;
    if (prop_name && Z_TYPE_P(prop_name) != IS_NULL) {
        ALLOC_INIT_ZVAL(prop_name_as_string);
        ddtrace_convert_to_string(prop_name_as_string, prop_name TSRMLS_CC);
        add_assoc_zval(el, "name", prop_name_as_string);
    }

    // SpanData::$resource defaults to SpanData::$name
    zval *prop_resource = ddtrace_spandata_property_resource(span);
    zval *prop_resource_as_string = NULL;
    zval resource_is_null;
    if (prop_resource &&
        (compare_function(&resource_is_null, &EG(uninitialized_zval), prop_resource TSRMLS_CC) == FAILURE ||
         Z_LVAL(resource_is_null) != 0)) {
        ALLOC_INIT_ZVAL(prop_resource_as_string);
        ddtrace_convert_to_string(prop_resource_as_string, prop_resource TSRMLS_CC);
        add_assoc_zval(el, "resource", prop_resource_as_string);
    } else if (prop_name_as_string) {
        Z_ADDREF_P(prop_name_as_string);
        add_assoc_zval(el, "resource", prop_name_as_string);
    }

    // TODO: SpanData::$service defaults to parent SpanData::$service or DD_SERVICE if root span
    zval *prop_service = ddtrace_spandata_property_service(span);
    zval *prop_service_as_string = NULL;
    if (prop_service && Z_TYPE_P(prop_service) != IS_NULL) {
        ALLOC_INIT_ZVAL(prop_service_as_string);
        ddtrace_convert_to_string(prop_service_as_string, prop_service TSRMLS_CC);

        HashTable *service_mappings = get_DD_SERVICE_MAPPING();
        zval **new_name;
        if (zend_hash_find(service_mappings, Z_STRVAL_P(prop_service_as_string), Z_STRLEN_P(prop_name_as_string) + 1,
                           (void **)&new_name) == SUCCESS) {
            zval_dtor(prop_service_as_string);
            ZVAL_COPY_VALUE(prop_service_as_string, *new_name);
            zval_copy_ctor(prop_service_as_string);
        }

        add_assoc_zval(el, "service", prop_service_as_string);
    }

    // SpanData::$type is optional and defaults to 'custom' at the Agent level
    zval *prop_type = ddtrace_spandata_property_type(span);
    zval *prop_type_as_string = NULL;
    if (prop_type && Z_TYPE_P(prop_type) != IS_NULL) {
        ALLOC_INIT_ZVAL(prop_type_as_string);
        ddtrace_convert_to_string(prop_type_as_string, prop_type TSRMLS_CC);
        add_assoc_zval(el, "type", prop_type_as_string);
    }

    _serialize_meta(el, span_fci TSRMLS_CC);

    zval *metrics = ddtrace_spandata_property_metrics(span), *metrics_zv = NULL;
    if (zend_hash_num_elements(Z_ARRVAL_P(metrics))) {
        ALLOC_INIT_ZVAL(metrics_zv);
        array_init(metrics_zv);
        HashPosition pos;
        zval **metric_value;
        for (zend_hash_internal_pointer_reset_ex(Z_ARRVAL_P(metrics), &pos);
             zend_hash_get_current_data_ex(Z_ARRVAL_P(metrics), (void **)&metric_value, &pos) == SUCCESS;
             zend_hash_move_forward_ex(Z_ARRVAL_P(metrics), &pos)) {
            ulong num_key;
            char *str_key;
            if (zend_hash_get_current_key_ex(Z_ARRVAL_P(metrics), &str_key, NULL, &num_key, 0, &pos) ==
                HASH_KEY_IS_STRING) {
                zval value;
                MAKE_COPY_ZVAL(metric_value, &value);
                convert_to_double(&value);
                add_assoc_double(metrics_zv, str_key, Z_DVAL(value));
            }
        }
        add_assoc_zval(el, "metrics", metrics_zv);
    }

    if (top_level_span && get_DD_TRACE_MEASURE_COMPILE_TIME()) {
        if (!metrics_zv) {
            ALLOC_INIT_ZVAL(metrics_zv);
            array_init(metrics_zv);
            add_assoc_zval(el, "metrics", metrics_zv);
        }
        add_assoc_double(metrics_zv, "php.compilation.total_time_ms", ddtrace_compile_time_get(TSRMLS_C) / 1000.);
    }

    add_next_index_zval(array, el);
}

static dd_error_info dd_fatal_error(int type, const char *format, va_list args) {
    dd_error_info error = {0};
    error.type = dd_fatal_error_type(type);
    error.msg = dd_fatal_error_msg(format, args);
    error.stack = dd_fatal_error_stack();
    return error;
}

void ddtrace_error_cb(DDTRACE_ERROR_CB_PARAMETERS) {
    TSRMLS_FETCH();

    /* We need the error handling to place nicely with the sandbox. The best
     * idea so far is to execute fatal error handling code iff the error handling
     * mode is set to EH_NORMAL. If it's something else, such as EH_SUPPRESS or
     * EH_THROW, then they are likely to be handled and accordingly they
     * shouldn't be treated as fatal.
     */

    bool is_fatal_error = type & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR);
    if (EXPECTED(EG(active)) && EG(error_handling) == EH_NORMAL && UNEXPECTED(is_fatal_error)) {
        /* If there is a fatal error in shutdown then this might not be an array
         * because we set it to IS_NULL in RSHUTDOWN. We probably want a more
         * robust way of detecting this, but I'm not sure how yet.
         */
        if (Z_TYPE(DDTRACE_G(additional_trace_meta)) == IS_ARRAY) {
            dd_error_info error = dd_fatal_error(type, format, args);
            dd_fatal_error_to_meta(&DDTRACE_G(additional_trace_meta), error);

            ddtrace_span_fci *span;
            for (span = DDTRACE_G(open_spans_top); span; span = span->next) {
                if (span->exception) {
                    continue;
                }

                dd_fatal_error_to_meta(ddtrace_spandata_property_meta(&span->span), error);
            }

            if (error.type) {
                zval_ptr_dtor(&error.type);
            }
            if (error.msg) {
                zval_ptr_dtor(&error.msg);
            }
            if (error.stack) {
                zval_ptr_dtor(&error.stack);
            }
            ddtrace_close_all_open_spans(TSRMLS_C);
        }
    }

    ddtrace_prev_error_cb(DDTRACE_ERROR_CB_PARAM_PASSTHRU);
}
