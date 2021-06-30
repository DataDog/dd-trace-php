#include <Zend/zend.h>
#include <Zend/zend_builtin_functions.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_interfaces.h>
#include <Zend/zend_types.h>
#include <inttypes.h>
#include <php.h>
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

typedef zend_result (*add_tag_fn_t)(void *context, ddtrace_string key, ddtrace_string value);

static zend_result dd_exception_to_error_msg(zend_object *exception, void *context, add_tag_fn_t add_tag) {
    ddtrace_string key = DDTRACE_STRING_LITERAL("error.msg");
    zend_string *msg = zai_exception_message(exception);
    ddtrace_string value = {ZSTR_VAL(msg), ZSTR_LEN(msg)};
    return add_tag(context, key, value);
}

static zend_result dd_exception_to_error_type(zend_object *exception, void *context, add_tag_fn_t add_tag) {
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

static zend_result dd_exception_to_error_stack(zend_object *exception, void *context, add_tag_fn_t add_tag) {
    zend_string *trace_string = zai_get_trace_without_args_from_exception(exception);
    ddtrace_string key = DDTRACE_STRING_LITERAL("error.stack");
    ddtrace_string value = {ZSTR_VAL(trace_string), ZSTR_LEN(trace_string)};
    zend_result result = add_tag(context, key, value);
    zend_string_release(trace_string);
    return result;
}

zend_result ddtrace_exception_to_meta(zend_object *exception, void *context, add_tag_fn_t add_meta) {
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

    // mask off flags such as E_DONT_BAIL
    code &= E_ALL;

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

static zend_result dd_add_meta_array(void *context, ddtrace_string key, ddtrace_string value) {
    zval *meta = context, tmp = ddtrace_zval_stringl(value.ptr, value.len);

    // meta array takes ownership of tmp
    return zend_symtable_str_update(Z_ARR_P(meta), key.ptr, key.len, &tmp) != NULL ? SUCCESS : FAILURE;
}

static void _serialize_meta(zval *el, ddtrace_span_fci *span_fci) {
    ddtrace_span_t *span = &span_fci->span;
    BOOL_T top_level_span = span->parent_id == DDTRACE_G(distributed_parent_trace_id);
    zval meta_zv, *meta = ddtrace_spandata_property_meta(span);

    array_init(&meta_zv);
    ZVAL_DEREF(meta);
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

    zend_bool error = ddtrace_hash_find_ptr(Z_ARR_P(meta), ZEND_STRL("error.msg")) ||
                      ddtrace_hash_find_ptr(Z_ARR_P(meta), ZEND_STRL("error.type"));
    if (error) {
        add_assoc_long(el, "error", 1);
    }

    if (top_level_span) {
        add_assoc_str(meta, "system.pid", zend_strpprintf(0, "%ld", (long)getpid()));
    }

    char *version = get_dd_version();
    if (*version) {  // non-empty
        add_assoc_string(meta, "version", version);
    }
    free(version);

    char *env = get_dd_env();
    if (*env) {  // non-empty
        add_assoc_string(meta, "env", env);
    }
    free(env);

    zend_array *global_tags = get_dd_tags();
    if (zend_hash_num_elements(global_tags) == 0) {
        zend_hash_release(global_tags);
        global_tags = get_dd_trace_global_tags();
    }
    zend_string *global_key;
    zval *global_val;
    ZEND_HASH_FOREACH_STR_KEY_VAL(global_tags, global_key, global_val) {
        Z_TRY_ADDREF_P(global_val);  // they're actually persistent strings, but doing the check does no harm here
        zend_hash_add(Z_ARR_P(meta), global_key, global_val);
    }
    ZEND_HASH_FOREACH_END();
    zend_hash_release(global_tags);

    zend_string *tag_key;
    zval *tag_value;
    ZEND_HASH_FOREACH_STR_KEY_VAL(DDTRACE_G(additional_global_tags), tag_key, tag_value) {
        Z_TRY_ADDREF_P(tag_value);
        zend_hash_add(Z_ARR_P(meta), tag_key, tag_value);
    }
    ZEND_HASH_FOREACH_END();

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
    BOOL_T top_level_span = span->parent_id == DDTRACE_G(distributed_parent_trace_id);
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
    ZVAL_DEREF(prop_name);
    if (Z_TYPE_P(prop_name) > IS_NULL) {
        zval prop_name_as_string;
        ddtrace_convert_to_string(&prop_name_as_string, prop_name);
        prop_name = zend_hash_str_update(Z_ARR_P(el), ZEND_STRL("name"), &prop_name_as_string);
    }

    // SpanData::$resource defaults to SpanData::$name
    zval *prop_resource = ddtrace_spandata_property_resource(span);
    ZVAL_DEREF(prop_resource);
    if (Z_TYPE_P(prop_resource) > IS_FALSE && (Z_TYPE_P(prop_resource) != IS_STRING || Z_STRLEN_P(prop_resource) > 0)) {
        _dd_add_assoc_zval_as_string(el, "resource", prop_resource);
    } else if (Z_TYPE_P(prop_name) > IS_NULL) {
        _add_assoc_zval_copy(el, "resource", prop_name);
    }

    // TODO: SpanData::$service defaults to parent SpanData::$service or DD_SERVICE if root span
    zval *prop_service = ddtrace_spandata_property_service(span);
    ZVAL_DEREF(prop_service);
    if (Z_TYPE_P(prop_service) > IS_NULL) {
        zval prop_service_as_string;
        ddtrace_convert_to_string(&prop_service_as_string, prop_service);

        zend_array *service_mappings = get_dd_service_mapping();
        zval *new_name = zend_hash_find(service_mappings, Z_STR(prop_service_as_string));
        if (new_name) {
            zend_string_release(Z_STR(prop_service_as_string));
            ZVAL_COPY(&prop_service_as_string, new_name);
        }
        zend_hash_release(service_mappings);

        add_assoc_zval(el, "service", &prop_service_as_string);
    }

    // SpanData::$type is optional and defaults to 'custom' at the Agent level
    zval *prop_type = ddtrace_spandata_property_type(span);
    ZVAL_DEREF(prop_type);
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
        metrics = zend_hash_str_add_new(Z_ARR_P(el), ZEND_STRL("metrics"), &metrics_zv);
    } else {
        metrics = NULL;
    }

    if (top_level_span && get_dd_trace_measure_compile_time()) {
        if (!metrics) {
            zval metrics_array;
            array_init(&metrics_array);
            metrics = zend_hash_str_add_new(Z_ARR_P(el), ZEND_STRL("metrics"), &metrics_array);
        }
        add_assoc_double(metrics, "php.compilation.total_time_ms", ddtrace_compile_time_get() / 1000.);
    }

    add_next_index_zval(array, el);
}

static zend_string *dd_truncate_uncaught_exception(zend_string *msg) {
    const char uncaught[] = "Uncaught ";
    const char *data = ZSTR_VAL(msg);
    size_t uncaught_len = sizeof uncaught - 1;  // ignore the null terminator
    size_t size = ZSTR_LEN(msg);
    if (size > uncaught_len && memcmp(data, uncaught, uncaught_len) == 0) {
        char *newline = memchr(data, '\n', size);
        if (newline) {
            size_t offset = newline - data;
            return zend_string_init(data, offset, 0);
        }
    }
    return zend_string_copy(msg);
}

void ddtrace_observer_error_cb(int type, const char *error_filename, uint32_t error_lineno, zend_string *message) {
    UNUSED(error_filename, error_lineno);

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
            dd_error_info error = {
                .type = dd_error_type(type),
                .msg = dd_truncate_uncaught_exception(message),
                .stack = dd_fatal_error_stack(),
            };
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
}
