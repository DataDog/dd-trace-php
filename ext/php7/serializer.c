#include <Zend/zend.h>
#include <Zend/zend_builtin_functions.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_interfaces.h>
#include <Zend/zend_smart_str.h>
#include <Zend/zend_types.h>
#include <inttypes.h>
#include <php.h>
#include <stdbool.h>
#include <stdlib.h>
#include <string.h>
// comment to prevent clang from reordering these headers
#include <exceptions/exceptions.h>
#include <properties/properties.h>

#include "arrays.h"
#include "compat_string.h"
#include "ddtrace.h"
#include "engine_api.h"
#include "engine_hooks.h"
#include "logging.h"
#include "mpack/mpack.h"
#include "span.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#define MAX_ID_BUFSIZ 21  // 1.8e^19 = 20 chars + 1 terminator
#define KEY_TRACE_ID "trace_id"
#define KEY_SPAN_ID "span_id"
#define KEY_PARENT_ID "parent_id"

static int msgpack_write_zval(mpack_writer_t *writer, zval *trace);

static int write_hash_table(mpack_writer_t *writer, HashTable *ht) {
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

        // Writing the key, if associative
        bool zval_string_as_uint64 = false;
        if (is_assoc == 1) {
            char *key = ZSTR_VAL(string_key);
            mpack_write_cstr(writer, key);
            // If the key is trace_id, span_id or parent_id then strings have to be converted to uint64 when packed.
            if (0 == strcmp(KEY_TRACE_ID, key) || 0 == strcmp(KEY_SPAN_ID, key) || 0 == strcmp(KEY_PARENT_ID, key)) {
                zval_string_as_uint64 = true;
            }
        }

        // Writing the value
        if (zval_string_as_uint64) {
            mpack_write_u64(writer, strtoull(Z_STRVAL_P(tmp), NULL, 10));
        } else if (msgpack_write_zval(writer, tmp) != 1) {
            return 0;
        }
    }
    ZEND_HASH_FOREACH_END();

    if (is_assoc == -1) {
        mpack_start_array(writer, 0);
        mpack_finish_array(writer);
    } else if (is_assoc) {
        mpack_finish_map(writer);
    } else {
        mpack_finish_array(writer);
    }
    return 1;
}

static int msgpack_write_zval(mpack_writer_t *writer, zval *trace) {
    if (Z_TYPE_P(trace) == IS_REFERENCE) {
        trace = Z_REFVAL_P(trace);
    }

    switch (Z_TYPE_P(trace)) {
        case IS_ARRAY:
            if (write_hash_table(writer, Z_ARRVAL_P(trace)) != 1) {
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
            mpack_write_cstr(writer, Z_STRVAL_P(trace));
            break;
        default:
            ddtrace_log_debug("Serialize values must be of type array, string, int, float, bool or null");
            return 0;
            break;
    }
    return 1;
}

int ddtrace_serialize_simple_array_into_c_string(zval *trace, char **data_p, size_t *size_p) {
    // encode to memory buffer
    char *data;
    size_t size;
    mpack_writer_t writer;
    mpack_writer_init_growable(&writer, &data, &size);
    if (msgpack_write_zval(&writer, trace) != 1) {
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

int ddtrace_serialize_simple_array(zval *trace, zval *retval) {
    // encode to memory buffer
    char *data;
    size_t size;

    if (ddtrace_serialize_simple_array_into_c_string(trace, &data, &size)) {
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

typedef ZEND_RESULT_CODE (*add_tag_fn_t)(void *context, ddtrace_string key, ddtrace_string value);

static ZEND_RESULT_CODE dd_exception_to_error_msg(zend_object *exception, void *context, add_tag_fn_t add_tag) {
    ddtrace_string key = DDTRACE_STRING_LITERAL("error.msg");
    zend_string *msg = zai_exception_message(exception);
    ddtrace_string value = {ZSTR_VAL(msg), ZSTR_LEN(msg)};
    return add_tag(context, key, value);
}

static int dd_exception_to_error_type(zend_object *exception, void *context,
                                      int (*add_tag)(void *context, ddtrace_string key, ddtrace_string value)) {
    ddtrace_string value, key = DDTRACE_STRING_LITERAL("error.type");

    if (instanceof_function(exception->ce, ddtrace_ce_fatal_error)) {
        zval *code = ZAI_EXCEPTION_PROPERTY(exception, ZEND_STR_CODE);
        const char *error_type_string = "{unknown error}";

        if (Z_TYPE_P(code) == IS_LONG) {
            switch (Z_LVAL_P(code)) {
                case E_ERROR:
                    error_type_string = "E_ERROR";
                    break;
                case E_CORE_ERROR:
                    error_type_string = "E_CORE_ERROR";
                    break;
                case E_COMPILE_ERROR:
                    error_type_string = "E_COMPILE_ERROR";
                    break;
                case E_USER_ERROR:
                    error_type_string = "E_USER_ERROR";
                    break;
                default:
                    ddtrace_assert_log_debug(
                        "Unhandled error type in DDTrace\\FatalError; is a fatal error case missing?");
            }

        } else {
            ddtrace_assert_log_debug("Exception was a DDTrace\\FatalError but failed to get an exception code");
        }

        value = ddtrace_string_cstring_ctor((char *)error_type_string);
    } else {
        zend_string *type_name = exception->ce->name;
        value.ptr = ZSTR_VAL(type_name);
        value.len = ZSTR_LEN(type_name);
    }

    return add_tag(context, key, value);
}

static ZEND_RESULT_CODE dd_exception_to_error_stack(zend_object *exception, void *context, add_tag_fn_t add_tag) {
    zend_string *trace_string = zai_get_trace_without_args_from_exception(exception);
    ddtrace_string key = DDTRACE_STRING_LITERAL("error.stack");
    ddtrace_string value = {ZSTR_VAL(trace_string), ZSTR_LEN(trace_string)};
    ZEND_RESULT_CODE result = add_tag(context, key, value);
    zend_string_release(trace_string);
    return result;
}

int ddtrace_exception_to_meta(zend_object *exception, void *context,
                              int (*add_meta)(void *context, ddtrace_string key, ddtrace_string value)) {
    bool success = dd_exception_to_error_msg(exception, context, add_meta) == SUCCESS &&
                   dd_exception_to_error_type(exception, context, add_meta) == SUCCESS &&
                   dd_exception_to_error_stack(exception, context, add_meta) == SUCCESS;
    return success ? SUCCESS : FAILURE;
}

typedef struct dd_error_info {
    zend_string *type;
    zend_string *msg;
    zend_string *stack;
} dd_error_info;

static zend_string *dd_error_type(int code) {
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

    return zend_string_init(error_type, strlen(error_type), 0);
}

static zend_string *dd_fatal_error_stack(void) {
    zval stack = {0};
    zend_fetch_debug_backtrace(&stack, 0, DEBUG_BACKTRACE_IGNORE_ARGS, 0);
    zend_string *error_stack = NULL;
    if (Z_TYPE(stack) == IS_ARRAY) {
        error_stack = zai_get_trace_without_args(Z_ARR(stack));
    }
    zval_ptr_dtor(&stack);
    return error_stack;
}

static zend_string *dd_vprintf_zstr(size_t len, const char *format, va_list args) {
    va_list args2;

    zend_string *msg = zend_string_alloc(len, 0);
    ZSTR_LEN(msg) = len;

    va_copy(args2, args);
    int written = vsnprintf(ZSTR_VAL(msg), len + 1, format, args2);
    va_end(args2);

    if (written < 0) {
        zend_string_release(msg);
        return NULL;
    }

    ZSTR_VAL(msg)[len] = '\0';
    return msg;
}

// Returns NULL in error conditions
static zend_string *dd_fatal_error_msg(const char *format, va_list args) {
    va_list args2;

    /* In PHP 7 an uncaught exception results in a fatal error. The error
     * message includes the call stack and its arguments, which is a vector for
     * information disclosure. We need to avoid this.
     *
     * We capture the first line of the error which won't include arguments.
     * The length of the buffer here is hopefully large enough to capture the
     * first line so we can avoid allocating the full string, as these can be
     * quite large and we're only interested in the first line.
     */
    const char uncaught[] = "Uncaught ";
    char buffer[256];

    va_copy(args2, args);
    int prefix = vsnprintf(buffer, sizeof buffer, format, args2);
    va_end(args2);

    if (prefix < 0) {
        return NULL;
    }

    /* The -1 is to avoid the null terminator which is included in literals and
     * will not be present in the rendered error message at that position.
     */
    size_t uncaught_len = sizeof uncaught - 1;
    if ((unsigned)prefix >= uncaught_len && memcmp(buffer, uncaught, uncaught_len) == 0) {
        char *newline = memchr(buffer, '\n', sizeof buffer);
        if (newline) {
            *newline = '\0';
            size_t linelen = newline - buffer;

            // +1 for null terminator
            zend_string *msg = zend_string_alloc(linelen + 1, 0);
            memcpy(ZSTR_VAL(msg), buffer, linelen + 1);  // copy NULL too
            ZSTR_LEN(msg) = linelen;

            return msg;
        } else {
            // our buffer wasn't big enough; we have to render the full message
            zend_string *msg = dd_vprintf_zstr(prefix, format, args);
            if (!msg) {
                return NULL;
            }

            newline = memchr(ZSTR_VAL(msg), '\n', sizeof buffer);
            if (UNEXPECTED(!newline)) {
                // This is suspect; there is always a newline in these messages
                zend_string_release(msg);
                return NULL;
            }

            size_t linepos = newline - ZSTR_VAL(msg);
            ZSTR_LEN(msg) = linepos;
            *newline = '\0';
            return msg;
        }
    }

    zend_string *msg = dd_vprintf_zstr(prefix, format, args);
    if (!msg) {
        return NULL;
    }

    return msg;
}

static dd_error_info dd_fatal_error(int type, const char *format, va_list args) {
    return (dd_error_info){
        .type = dd_error_type(type),
        .msg = dd_fatal_error_msg(format, args),
        .stack = dd_fatal_error_stack(),
    };
}

static int dd_fatal_error_to_meta(zval *meta, dd_error_info error) {
    HashTable *ht = Z_ARR_P(meta);

    if (error.type) {
        zval tmp = ddtrace_zval_zstr(zend_string_copy(error.type));
        zend_symtable_str_update(ht, ZEND_STRL("error.type"), &tmp);
    }

    if (error.msg) {
        zval tmp = ddtrace_zval_zstr(zend_string_copy(error.msg));
        zend_symtable_str_update(ht, ZEND_STRL("error.msg"), &tmp);
    }

    if (error.stack) {
        zval tmp = ddtrace_zval_zstr(zend_string_copy(error.stack));
        zend_symtable_str_update(ht, ZEND_STRL("error.stack"), &tmp);
    }

    return error.type && error.msg ? SUCCESS : FAILURE;
}

static int dd_add_meta_array(void *context, ddtrace_string key, ddtrace_string value) {
    zval *meta = context, tmp = ddtrace_zval_stringl(value.ptr, value.len);

    // meta array takes ownership of tmp
    return zend_symtable_str_update(Z_ARR_P(meta), key.ptr, key.len, &tmp) != NULL ? SUCCESS : FAILURE;
}

static void _serialize_meta(zval *el, ddtrace_span_fci *span_fci) {
    ddtrace_span_t *span = &span_fci->span;
    zval meta_zv, *meta = ddtrace_spandata_property_meta(span);

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

    if (span_fci->exception) {
        ddtrace_exception_to_meta(span_fci->exception, meta, dd_add_meta_array);
    }

    zval *error = ddtrace_hash_find_ptr(Z_ARR_P(meta), ZEND_STRL("error.msg"));
    if (error) {
        add_assoc_long(el, "error", 1);
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

void ddtrace_serialize_span_to_array(ddtrace_span_fci *span_fci, zval *array) {
    ddtrace_span_t *span = &span_fci->span;
    zval *el;
    zval zv;
    el = &zv;
    array_init(el);

    char trace_id_str[MAX_ID_BUFSIZ];
    sprintf(trace_id_str, "%" PRIu64, span->trace_id);
    add_assoc_string(el, KEY_TRACE_ID, trace_id_str);

    char span_id_str[MAX_ID_BUFSIZ];
    sprintf(span_id_str, "%" PRIu64, span->span_id);
    add_assoc_string(el, KEY_SPAN_ID, span_id_str);

    if (span->parent_id > 0) {
        char parent_id_str[MAX_ID_BUFSIZ];
        sprintf(parent_id_str, "%" PRIu64, span->parent_id);
        add_assoc_string(el, KEY_PARENT_ID, parent_id_str);
    }
    add_assoc_long(el, "start", span->start);
    add_assoc_long(el, "duration", span->duration);

    // SpanData::$name defaults to fully qualified called name (set at span close)
    zval *prop_name = ddtrace_spandata_property_name(span);
    zval prop_name_as_string;
    if (Z_TYPE_P(prop_name) > IS_NULL) {
        ddtrace_convert_to_string(&prop_name_as_string, prop_name);
        _add_assoc_zval_copy(el, "name", &prop_name_as_string);
    }

    // SpanData::$resource defaults to SpanData::$name
    zval *prop_resource = ddtrace_spandata_property_resource(span);
    if (Z_TYPE_P(prop_resource) > IS_FALSE && (Z_TYPE_P(prop_resource) != IS_STRING || Z_STRLEN_P(prop_resource) > 0)) {
        _dd_add_assoc_zval_as_string(el, "resource", prop_resource);
    } else if (Z_TYPE_P(prop_name) > IS_NULL) {
        _add_assoc_zval_copy(el, "resource", &prop_name_as_string);
    }

    if (Z_TYPE_P(prop_name) > IS_NULL) {
        zval_dtor(&prop_name_as_string);
    }

    // TODO: SpanData::$service defaults to parent SpanData::$service or DD_SERVICE if root span
    zval *prop_service = ddtrace_spandata_property_service(span);
    if (Z_TYPE_P(prop_service) > IS_NULL) {
        _dd_add_assoc_zval_as_string(el, "service", prop_service);
    }

    // SpanData::$type is optional and defaults to 'custom' at the Agent level
    zval *prop_type = ddtrace_spandata_property_type(span);
    if (Z_TYPE_P(prop_type) > IS_NULL) {
        _dd_add_assoc_zval_as_string(el, "type", prop_type);
    }

    _serialize_meta(el, span_fci);

    zval *metrics = ddtrace_spandata_property_metrics(span);
    ZVAL_DEREF(metrics);
    if (Z_TYPE_P(metrics) == IS_ARRAY && zend_hash_num_elements(Z_ARR_P(metrics))) {
        zval metrics_zv;
        array_init(&metrics_zv);
        zend_string *str_key;
        zval *val;
        ZEND_HASH_FOREACH_STR_KEY_VAL_IND(Z_ARR_P(metrics), str_key, val) {
            if (str_key) {
                add_assoc_double(&metrics_zv, ZSTR_VAL(str_key), zval_get_double(val));
            }
        }
        ZEND_HASH_FOREACH_END();
        add_assoc_zval(el, "metrics", &metrics_zv);
    }

    add_next_index_zval(array, el);
}

void ddtrace_error_cb(DDTRACE_ERROR_CB_PARAMETERS) {
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

                zval *meta = ddtrace_spandata_property_meta(&span->span);
                if (Z_TYPE_P(meta) != IS_ARRAY) {
                    zval_ptr_dtor(meta);
                    array_init_size(meta, ddtrace_num_error_tags);
                }
                dd_fatal_error_to_meta(meta, error);
            }
            if (error.type) {
                zend_string_release(error.type);
            }
            if (error.msg) {
                zend_string_release(error.msg);
            }
            if (error.stack) {
                zend_string_release(error.stack);
            }
            ddtrace_close_all_open_spans();
        }
    }

    ddtrace_prev_error_cb(DDTRACE_ERROR_CB_PARAM_PASSTHRU);
}
