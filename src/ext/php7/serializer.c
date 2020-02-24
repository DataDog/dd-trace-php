#include <Zend/zend.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_interfaces.h>
#include <Zend/zend_smart_str.h>
#include <php.h>

#include <ext/spl/spl_exceptions.h>

#include "arrays.h"
#include "compat_string.h"
#include "ddtrace.h"
#include "logging.h"
#include "mpack/mpack.h"
#include "span.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static int msgpack_write_zval(mpack_writer_t *writer, zval *trace TSRMLS_DC);

static int write_hash_table(mpack_writer_t *writer, HashTable *ht TSRMLS_DC) {
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

static int msgpack_write_zval(mpack_writer_t *writer, zval *trace TSRMLS_DC) {
    if (Z_TYPE_P(trace) == IS_REFERENCE) {
        trace = Z_REFVAL_P(trace);
    }

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
        case IS_TRUE:
        case IS_FALSE:
            mpack_write_bool(writer, Z_TYPE_P(trace) == IS_TRUE);
            break;
        case IS_STRING:
            mpack_write_cstr(writer, ZSTR_VAL(Z_STR_P(trace)));
            break;
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
        ZVAL_STRINGL(retval, data, size);
        free(data);
        return 1;
    } else {
        return 0;
    }
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

static void dd_serialize_exception(zval *el, zval *meta, zend_object *exception_obj) {
    zval exception, name, msg, code, stack;
    if (!exception_obj) {
        return;
    }

    ZVAL_OBJ(&exception, exception_obj);

    add_assoc_long(el, "error", 1);

    ZVAL_STR(&name, Z_OBJCE(exception)->name);
    zend_call_method_with_0_params(&exception, Z_OBJCE(exception), NULL, "getmessage", &msg);

    if (instanceof_function(Z_OBJCE(exception), ddtrace_ce_fatal_error)) {
        zend_call_method_with_0_params(&exception, Z_OBJCE(exception), NULL, "getcode", &code);
        if (Z_TYPE_INFO(code) == IS_LONG) {
            switch (Z_LVAL(code)) {
                case E_ERROR:
                    add_assoc_string(meta, "error.type", "E_ERROR");
                    break;
                case E_CORE_ERROR:
                    add_assoc_string(meta, "error.type", "E_CORE_ERROR");
                    break;
                case E_USER_ERROR:
                    add_assoc_string(meta, "error.type", "E_USER_ERROR");
                    break;
                default:
                    add_assoc_string(meta, "error.type", "{unknown error}");
                    break;
            }
        }
    } else {
        _add_assoc_zval_copy(meta, "error.type", &name);
    }

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

static void _serialize_meta(zval *el, ddtrace_span_fci *span_fci) {
    ddtrace_span_t *span = &span_fci->span;
    zval meta_zv, *meta = ddtrace_spandata_property_meta(span->span_data);

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

    dd_serialize_exception(el, meta, span_fci->exception);
    if (!span_fci->exception) {
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

static void _dd_add_assoc_zval_as_string(zval *el, const char *name, zval *value) {
    zval value_as_string;
    ddtrace_convert_to_string(&value_as_string, value);
    _add_assoc_zval_copy(el, name, &value_as_string);
    zval_dtor(&value_as_string);
}

void ddtrace_serialize_span_to_array(ddtrace_span_fci *span_fci, zval *array TSRMLS_DC) {
    ddtrace_span_t *span = &span_fci->span;
    zval *el;
    zval zv;
    el = &zv;
    array_init(el);

    add_assoc_long(el, "trace_id", span->trace_id);
    add_assoc_long(el, "span_id", span->span_id);
    if (span->parent_id > 0) {
        add_assoc_long(el, "parent_id", span->parent_id);
    }
    add_assoc_long(el, "start", span->start);
    add_assoc_long(el, "duration", span->duration);

    // SpanData::$name defaults to fully qualified called name (set at span close)
    zval *prop_name = ddtrace_spandata_property_name(span->span_data);
    zval prop_name_as_string;
    if (Z_TYPE_P(prop_name) != IS_NULL) {
        ddtrace_convert_to_string(&prop_name_as_string, prop_name);
        _add_assoc_zval_copy(el, "name", &prop_name_as_string);
    }

    // SpanData::$resource defaults to SpanData::$name
    zval *prop_resource = ddtrace_spandata_property_resource(span->span_data);
    if (Z_TYPE_P(prop_resource) != IS_NULL) {
        _dd_add_assoc_zval_as_string(el, "resource", prop_resource);
    } else {
        _add_assoc_zval_copy(el, "resource", &prop_name_as_string);
    }

    if (Z_TYPE_P(prop_name) != IS_NULL) {
        zval_dtor(&prop_name_as_string);
    }

    // TODO: SpanData::$service defaults to parent SpanData::$service or DD_SERVICE if root span
    zval *prop_service = ddtrace_spandata_property_service(span->span_data);
    if (Z_TYPE_P(prop_service) != IS_NULL) {
        _dd_add_assoc_zval_as_string(el, "service", prop_service);
    }

    // SpanData::$type is optional and defaults to 'custom' at the Agent level
    zval *prop_type = ddtrace_spandata_property_type(span->span_data);
    if (Z_TYPE_P(prop_type) != IS_NULL) {
        _dd_add_assoc_zval_as_string(el, "type", prop_type);
    }

    _serialize_meta(el, span_fci TSRMLS_CC);

    zval *metrics = ddtrace_spandata_property_metrics(span->span_data);
    if (Z_TYPE_P(metrics) == IS_ARRAY) {
        _add_assoc_zval_copy(el, "metrics", metrics);
    }

    add_next_index_zval(array, el);
}
