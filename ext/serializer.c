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

#include <ext/standard/php_string.h>
#include <components-rs/ddtrace.h>
// comment to prevent clang from reordering these headers
#include <SAPI.h>
#include <exceptions/exceptions.h>
#include <json/json.h>
#ifndef _WIN32
#include <stdatomic.h>
#else
#include <components/atomic_win32_polyfill.h>
#include <synchapi.h>
#endif
#include <vendor/mpack/mpack.h>
#include <zai_string/string.h>
#include <sandbox/sandbox.h>

#include "arrays.h"
#include "compat_string.h"
#include "ddtrace.h"
#include "engine_api.h"
#include "engine_hooks.h"
#include "ip_extraction.h"
#include <components/log/log.h>
#include "priority_sampling/priority_sampling.h"
#include "span.h"
#include "uri_normalization.h"
#include "user_request.h"
#include "ddshared.h"
#include "zend_hrtime.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

extern void (*profiling_notify_trace_finished)(uint64_t local_root_span_id,
                                               zai_str span_type,
                                               zai_str resource);

static void mpack_write_utf8_lossy_cstr(mpack_writer_t *writer, const char *str, size_t len) {
    if (get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        char *strippedStr = ddtrace_strip_invalid_utf8(str, &len);
        if (strippedStr) {
            mpack_write_str(writer, strippedStr, len);
            ddtrace_drop_rust_string(strippedStr, len);
            return;
        }
    }

    mpack_write_str(writer, str, len);
}

#define MAX_ID_BUFSIZ 40  // 3.4e^38 = 39 chars + 1 terminator
#define KEY_TRACE_ID "trace_id"
#define KEY_SPAN_ID "span_id"
#define KEY_PARENT_ID "parent_id"
#define KEY_META_STRUCT "meta_struct"

static int msgpack_write_zval(mpack_writer_t *writer, zval *trace, int level);
static void serialize_meta_struct(mpack_writer_t *writer, zval *trace);

static int write_hash_table(mpack_writer_t *writer, HashTable *ht, int level) {
    zval *tmp;
    zend_string *string_key;
    zend_long num_key;
    bool is_assoc = 0;

#if PHP_VERSION_ID >= 80100
    is_assoc = !zend_array_is_list(ht);
#else
    Bucket *bucket;
    ZEND_HASH_FOREACH_BUCKET(ht, bucket) { is_assoc = is_assoc || bucket->key != NULL; }
    ZEND_HASH_FOREACH_END();
#endif

    if (is_assoc) {
        mpack_start_map(writer, zend_hash_num_elements(ht));
    } else {
        mpack_start_array(writer, zend_hash_num_elements(ht));
    }

    ZEND_HASH_FOREACH_KEY_VAL_IND(ht, num_key, string_key, tmp) {
        // Writing the key, if associative
        bool zval_string_as_uint64 = false;
        bool is_meta_struct = false;
        if (is_assoc == 1) {
            char num_str_buf[MAX_ID_BUFSIZ], *key;
            size_t len;
            if (string_key) {
                key = ZSTR_VAL(string_key);
                len = ZSTR_LEN(string_key);
            } else {
                key = num_str_buf;
                len = sprintf(num_str_buf, ZEND_LONG_FMT, num_key);
            }
            mpack_write_utf8_lossy_cstr(writer, key, len);
            // If the key is trace_id, span_id or parent_id then strings have to be converted to uint64 when packed.
            if (level <= 3 &&
                (0 == strcmp(KEY_TRACE_ID, key) || 0 == strcmp(KEY_SPAN_ID, key) || 0 == strcmp(KEY_PARENT_ID, key))) {
                zval_string_as_uint64 = true;
            }
            if (level <= 3 &&
                (0 == strcmp(KEY_META_STRUCT, key))) {
                is_meta_struct = true;
            }
        }

        // Writing the value
        if (zval_string_as_uint64) {
            mpack_write_u64(writer, strtoull(Z_STRVAL_P(tmp), NULL, 10));
        } else if(is_meta_struct) {
            serialize_meta_struct(writer, tmp);
        } else if (msgpack_write_zval(writer, tmp, level) != 1) {
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

static int msgpack_write_zval(mpack_writer_t *writer, zval *trace, int level) {
    if (Z_TYPE_P(trace) == IS_REFERENCE) {
        trace = Z_REFVAL_P(trace);
    }
    switch (Z_TYPE_P(trace)) {
        case IS_ARRAY:
            if (write_hash_table(writer, Z_ARRVAL_P(trace), level + 1) != 1) {
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
            mpack_write_utf8_lossy_cstr(writer, Z_STRVAL_P(trace), Z_STRLEN_P(trace));
            break;
        default:
            LOG(WARN, "Serialize values must be of type array, string, int, float, bool or null");
            mpack_writer_flag_error(writer, mpack_error_type);
            return 0;
    }
    return 1;
}

static void serialize_meta_struct(mpack_writer_t *writer, zval *meta_struct) {
    zval *tmp;
    zend_string *string_key;

    HashTable *ht = Z_ARRVAL_P(meta_struct);

    mpack_start_map(writer, zend_hash_num_elements(ht));

    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(ht, string_key, tmp) {
        if (!string_key) {
            continue;
        }
        mpack_write_cstr(writer, ZSTR_VAL(string_key));
        mpack_write_bin(writer, Z_STRVAL_P(tmp), Z_STRLEN_P(tmp));
    }
    ZEND_HASH_FOREACH_END();

    mpack_finish_map(writer);
}

int ddtrace_serialize_simple_array_into_c_string(zval *trace, char **data_p, size_t *size_p) {
    // encode to memory buffer
    char *data;
    size_t size;
    mpack_writer_t writer;
    mpack_writer_init_growable(&writer, &data, &size);
    if (msgpack_write_zval(&writer, trace, 0) != 1) {
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

size_t ddtrace_serialize_simple_array_into_mapped_menory(zval *trace, char *map, size_t size) {
    // encode to memory buffer
    mpack_writer_t writer;
    mpack_writer_init(&writer, map, size);
    if (msgpack_write_zval(&writer, trace, 0) != 1) {
        mpack_writer_destroy(&writer);
        return 0;
    }
    size_t written = mpack_writer_buffer_used(&writer);
    // finish writing
    if (mpack_writer_destroy(&writer) != mpack_ok) {
        return 0;
    }
    return written;
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

#if PHP_VERSION_ID < 70100
#define ZEND_STR_LINE "line"
#define ZEND_STR_FILE "file"
#define ZEND_STR_PREVIOUS "previous"
#endif

enum dd_exception {
    DD_EXCEPTION_THROWN,
    DD_EXCEPTION_CAUGHT,
    DD_EXCEPTION_UNCAUGHT,
};

static zend_result dd_exception_to_error_msg(zend_object *exception, void *context, add_tag_fn_t add_tag, enum dd_exception exception_state) {
    zend_string *msg = zai_exception_message(exception);
    zend_long line = zval_get_long(ZAI_EXCEPTION_PROPERTY(exception, ZEND_STR_LINE));
    zend_string *file = ddtrace_convert_to_str(ZAI_EXCEPTION_PROPERTY(exception, ZEND_STR_FILE));

    char *error_text, *status_line = NULL;

    if (SG(sapi_headers).http_response_code >= 500) {
        if (SG(sapi_headers).http_status_line) {
            UNUSED(asprintf(&status_line, " (%s)", SG(sapi_headers).http_status_line));
        } else {
            UNUSED(asprintf(&status_line, " (%d)", SG(sapi_headers).http_response_code));
        }
    }

    const char *exception_type;
    switch (exception_state) {
        case DD_EXCEPTION_CAUGHT: exception_type = "Caught"; break;
        case DD_EXCEPTION_UNCAUGHT: exception_type = "Uncaught"; break;
        default: exception_type = "Thrown"; break;
    }

    int error_len = asprintf(&error_text, "%s %s%s%s%s in %s:" ZEND_LONG_FMT, exception_type,
                             ZSTR_VAL(exception->ce->name), status_line ? status_line : "", ZSTR_LEN(msg) > 0 ? ": " : "",
                             ZSTR_VAL(msg), ZSTR_VAL(file), line);

    free(status_line);

    ddtrace_string key = DDTRACE_STRING_LITERAL("error.message");
    ddtrace_string value = {error_text, error_len};
    zend_result result = add_tag(context, key, value);

    zend_string_release(file);
    free(error_text);
    return result;
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
                    LOG_UNREACHABLE(
                        "Unhandled error type in DDTrace\\FatalError; is a fatal error case missing?");
            }

        } else {
            LOG_UNREACHABLE("Exception was a DDTrace\\FatalError but failed to get an exception code");
        }

        value = ddtrace_string_cstring_ctor((char *)error_type_string);
    } else {
        zend_string *type_name = exception->ce->name;
        value.ptr = ZSTR_VAL(type_name);
        value.len = ZSTR_LEN(type_name);
    }

    return add_tag(context, key, value);
}

static zend_result dd_exception_trace_to_error_stack(zend_string *trace, void *context, add_tag_fn_t add_tag) {
    ddtrace_string key = DDTRACE_STRING_LITERAL("error.stack");
    ddtrace_string value = {ZSTR_VAL(trace), ZSTR_LEN(trace)};
    zend_result result = add_tag(context, key, value);
    zend_string_release(trace);
    return result;
}

// Guarantees that add_tag will only be called once per tag, will stop trying to add tags if one fails.
static zend_result ddtrace_exception_to_meta(zend_object *exception, void *context, add_tag_fn_t add_meta, enum dd_exception exception_state) {
    zend_object *exception_root = exception;
    zend_string *full_trace = zai_get_trace_without_args_from_exception(exception);

    zval *previous = ZAI_EXCEPTION_PROPERTY(exception, ZEND_STR_PREVIOUS);
    while (Z_TYPE_P(previous) == IS_OBJECT && !Z_IS_RECURSIVE_P(previous) &&
           instanceof_function(Z_OBJCE_P(previous), zend_ce_throwable)) {
        zend_string *trace_string = zai_get_trace_without_args_from_exception(Z_OBJ_P(previous));

        zend_string *msg = zai_exception_message(exception);
        zend_long line = zval_get_long(ZAI_EXCEPTION_PROPERTY(exception, ZEND_STR_LINE));
        zend_string *file = ddtrace_convert_to_str(ZAI_EXCEPTION_PROPERTY(exception, ZEND_STR_FILE));

        zend_string *complete_trace =
            zend_strpprintf(0, "%s\n\nNext %s%s%s in %s:" ZEND_LONG_FMT "\nStack trace:\n%s", ZSTR_VAL(trace_string),
                            ZSTR_VAL(exception->ce->name), ZSTR_LEN(msg) ? ": " : "", ZSTR_VAL(msg), ZSTR_VAL(file),
                            line, ZSTR_VAL(full_trace));
        zend_string_release(trace_string);
        zend_string_release(full_trace);
        zend_string_release(file);
        full_trace = complete_trace;

        Z_PROTECT_RECURSION_P(previous);
        exception = Z_OBJ_P(previous);
        previous = ZAI_EXCEPTION_PROPERTY(exception, ZEND_STR_PREVIOUS);
    }

    previous = ZAI_EXCEPTION_PROPERTY(exception_root, ZEND_STR_PREVIOUS);
    while (Z_TYPE_P(previous) == IS_OBJECT && !Z_IS_RECURSIVE_P(previous) &&
           instanceof_function(Z_OBJCE_P(previous), zend_ce_throwable)) {
        Z_UNPROTECT_RECURSION_P(previous);
        previous = ZAI_EXCEPTION_PROPERTY(Z_OBJ_P(previous), ZEND_STR_PREVIOUS);
    }

    bool success = dd_exception_to_error_msg(exception, context, add_meta, exception_state) == SUCCESS &&
                   dd_exception_to_error_type(exception, context, add_meta) == SUCCESS &&
                   dd_exception_trace_to_error_stack(full_trace, context, add_meta) == SUCCESS;
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

static int dd_fatal_error_to_meta(zend_array *meta, dd_error_info error) {
    if (error.type) {
        zval tmp = ddtrace_zval_zstr(zend_string_copy(error.type));
        zend_symtable_str_update(meta, ZEND_STRL("error.type"), &tmp);
    }

    if (error.msg) {
        zval tmp = ddtrace_zval_zstr(zend_string_copy(error.msg));
        zend_symtable_str_update(meta, ZEND_STRL("error.message"), &tmp);
    }

    if (error.stack) {
        zval tmp = ddtrace_zval_zstr(zend_string_copy(error.stack));
        zend_symtable_str_update(meta, ZEND_STRL("error.stack"), &tmp);
    }

    return error.type && error.msg ? SUCCESS : FAILURE;
}

static zend_result dd_add_meta_array(void *context, ddtrace_string key, ddtrace_string value) {
    zval *meta = context, tmp = ddtrace_zval_stringl(value.ptr, value.len);

    // meta array takes ownership of tmp
    return zend_symtable_str_update(Z_ARR_P(meta), key.ptr, key.len, &tmp) != NULL ? SUCCESS : FAILURE;
}

static void dd_add_header_to_meta(zend_array *meta, const char *type, zend_string *lowerheader,
                                  zend_string *headerval) {
    if (zend_hash_exists(get_DD_TRACE_HEADER_TAGS(), lowerheader)) {
        for (char *ptr = ZSTR_VAL(lowerheader); *ptr; ++ptr) {
            if ((*ptr < 'a' || *ptr > 'z') && *ptr != '-' && (*ptr < '0' || *ptr > '9')) {
                *ptr = '_';
            }
        }

        zend_string *headertag = zend_strpprintf(0, "http.%s.headers.%s", type, ZSTR_VAL(lowerheader));
        zval headerzv;
        ZVAL_STR_COPY(&headerzv, headerval);
        zend_hash_update(meta, headertag, &headerzv);
        zend_string_release(headertag);
    }
}

static void normalize_with_underscores(zend_string *str) {
    for (char *ptr = ZSTR_VAL(str); *ptr; ++ptr) {
        // Replace non-alphanumeric/dashes by underscores
        if ((*ptr < 'a' || *ptr > 'z')
            && (*ptr < 'A' || *ptr > 'Z')
            && (*ptr < '0' || *ptr > '9')
            && *ptr != '-') {
            *ptr = '_';
        }
    }
}

static void dd_add_post_fields_to_meta(zend_array *meta, const char *type, zend_string *postkey, zend_string *postval) {
    zend_string *posttag = zend_strpprintf(0, "http.%s.post.%s", type, ZSTR_VAL(postkey));
    zval postzv;
    ZVAL_STR_COPY(&postzv, postval);
    zend_hash_update(meta, posttag, &postzv);
    zend_string_release(posttag);
}

static void dd_add_post_fields_to_meta_recursive(zend_array *meta, const char *type, zend_string *postkey,
                                                 zval *postval, zend_array* post_whitelist,
                                                 bool is_prefixed) {
    if (Z_TYPE_P(postval) == IS_ARRAY) {
        zend_ulong index;
        zend_string *key;
        zval *val;

        ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(postval), index, key, val) {
            if (key) {
                zend_string *copy_key = zend_string_dup(key, 0);
                normalize_with_underscores(copy_key);
                if (ZSTR_LEN(postkey) == 0) {
                    dd_add_post_fields_to_meta_recursive(meta, type, copy_key, val, post_whitelist,
                                                         is_prefixed || zend_hash_exists(post_whitelist, copy_key));
                } else {
                    // If the current postkey is not the empty string, we want to add a '.' to the beginning of the key
                    zend_string *newkey = zend_strpprintf(0, "%s.%s", ZSTR_VAL(postkey), ZSTR_VAL(copy_key));
                    dd_add_post_fields_to_meta_recursive(meta, type, newkey, val, post_whitelist,
                                                         is_prefixed || zend_hash_exists(post_whitelist, newkey));
                    zend_string_release(newkey);
                }
                zend_string_release(copy_key);
            } else {
                // Use numeric index if there isn't a string key
                zend_string *newkey = zend_strpprintf(0, "%s." ZEND_LONG_FMT, ZSTR_VAL(postkey), index);
                dd_add_post_fields_to_meta_recursive(meta, type, newkey, val, post_whitelist,
                                                     is_prefixed || zend_hash_exists(post_whitelist, newkey));
                zend_string_release(newkey);
            }
        }
        ZEND_HASH_FOREACH_END();
    } else {
        if (is_prefixed) { // The postkey is in the whitelist or is prefixed by a key in the whitelist
            // we want to add it to the meta as is
            zend_string *ztr_postval = zval_get_string(postval);
            dd_add_post_fields_to_meta(meta, type, postkey, ztr_postval);
            zend_string_release(ztr_postval);
        } else if (post_whitelist) {
            zend_string *str;
            zend_ulong numkey;
            zend_hash_get_current_key(post_whitelist, &str, &numkey);
            if (str && zend_string_equals_literal(str, "*")) { // '*' is a wildcard for the whitelist
                // Here, both the postkey and postval are strings, so we can concatenate them into "<postkey>=<postval>"
                zend_string *postvalstr = zval_get_string(postval);
                zend_string *postvalconcat = zend_strpprintf(0, "%s=%s", ZSTR_VAL(postkey), ZSTR_VAL(postvalstr));
                zend_string_release(postvalstr);

                // Match it with the regex to redact if needed
                if (zai_match_regex(get_DD_TRACE_OBFUSCATION_QUERY_STRING_REGEXP(), postvalconcat)) {
                    zend_string *replacement = zend_string_init(ZEND_STRL("<redacted>"), 0);
                    dd_add_post_fields_to_meta(meta, type, postkey, replacement);
                    zend_string_release(replacement);
                } else {
                    dd_add_post_fields_to_meta(meta, type, postkey, postvalstr);
                }
                zend_string_release(postvalconcat);
            } else { // No wildcard and the postkey isn't in the whitelist
                // Always use "<redacted>" as the value
                zend_string *replacement = zend_string_init(ZEND_STRL("<redacted>"), 0);
                dd_add_post_fields_to_meta(meta, type, postkey, replacement);
                zend_string_release(replacement);
            }
        } else { // No whitelist, so we always use "<redacted>" as the value
            zend_string *replacement = zend_string_init(ZEND_STRL("<redacted>"), 0);
            dd_add_post_fields_to_meta(meta, type, postkey, replacement);
            zend_string_release(replacement);
        }
    }
}

void ddtrace_set_global_span_properties(ddtrace_span_data *span) {
    zend_array *meta = ddtrace_property_array(&span->property_meta);

    zend_array *global_tags = get_DD_TAGS();
    zend_string *global_key;
    zval *global_val;
    ZEND_HASH_FOREACH_STR_KEY_VAL(global_tags, global_key, global_val) {
        if (zend_hash_add(meta, global_key, global_val)) {
            Z_TRY_ADDREF_P(global_val);
        }
    }
    ZEND_HASH_FOREACH_END();

    zend_string *tag_key;
    zval *tag_value;
    ZEND_HASH_FOREACH_STR_KEY_VAL(DDTRACE_G(additional_global_tags), tag_key, tag_value) {
        if (zend_hash_add(meta, tag_key, tag_value)) {
            Z_TRY_ADDREF_P(tag_value);
        }
    }
    ZEND_HASH_FOREACH_END();

    zval_ptr_dtor(&span->property_id);
    ZVAL_STR(&span->property_id, ddtrace_span_id_as_string(span->span_id));
}

static const char *dd_get_req_uri(zend_array *_server) {
    const char *uri = NULL;
    if (_server) {
        zval *req_uri = zend_hash_str_find(_server, ZEND_STRL("REQUEST_URI"));
        if (req_uri && Z_TYPE_P(req_uri) == IS_STRING) {
            uri = Z_STRVAL_P(req_uri);
        }
    }

    if (!uri) {
        uri = SG(request_info).request_uri;
    }

    return uri;
}

static const char *dd_get_query_string(zend_array *_server) {
    const char *query_string = NULL;
    if (_server) {
        zval *query_str = zend_hash_str_find(_server, ZEND_STRL("QUERY_STRING"));
        if (query_str && Z_TYPE_P(query_str) == IS_STRING) {
            query_string = Z_STRVAL_P(query_str);
        }
    }

    if (!query_string) {
        query_string = SG(request_info).query_string;
    }

    return query_string;
}

static zend_string *dd_build_req_url(zend_array *_server) {
    const char *uri = dd_get_req_uri(_server);
    if (!uri) {
        return ZSTR_EMPTY_ALLOC();
    }

    zval *https = zend_hash_str_find(_server, ZEND_STRL("HTTPS"));
    bool is_https = https && i_zend_is_true(https);

    zval *host_zv;
    if ((!(host_zv = zend_hash_str_find(_server, ZEND_STRL("HTTP_HOST"))) &&
         !(host_zv = zend_hash_str_find(_server, ZEND_STRL("SERVER_NAME")))) ||
        Z_TYPE_P(host_zv) != IS_STRING) {
        return ZSTR_EMPTY_ALLOC();
    }

    int uri_len;
    char *question_mark = strchr(uri, '?');
    zend_string *query_string = ZSTR_EMPTY_ALLOC();
    if (question_mark) {
        uri_len = question_mark - uri;
        query_string = zai_filter_query_string(
                (zai_str)ZAI_STR_NEW(question_mark + 1, strlen(uri) - uri_len - 1),
            get_DD_TRACE_HTTP_URL_QUERY_PARAM_ALLOWED(), get_DD_TRACE_OBFUSCATION_QUERY_STRING_REGEXP());
    } else {
        uri_len = strlen(uri);
    }

    zend_string *url =
        zend_strpprintf(0, "http%s://%s%.*s%s%s", is_https ? "s" : "", Z_STRVAL_P(host_zv), uri_len, uri,
                        ZSTR_LEN(query_string) ? "?" : "", ZSTR_VAL(query_string));

    zend_string_release(query_string);

    return url;
}

static zend_string *dd_get_user_agent(zend_array *_server) {
    if (_server) {
        zval *user_agent = zend_hash_str_find(_server, ZEND_STRL("HTTP_USER_AGENT"));
        if (user_agent && Z_TYPE_P(user_agent) == IS_STRING) {
            return Z_STR_P(user_agent);
        }
    }
    return ZSTR_EMPTY_ALLOC();
}

static bool dd_set_mapped_peer_service(zval *meta, zend_string *peer_service) {
    zend_array *peer_service_mapping = get_DD_TRACE_PEER_SERVICE_MAPPING();
    if (zend_hash_num_elements(peer_service_mapping) == 0 || !meta || !peer_service) {
        return false;
    }

    zval* mapped_service_zv = zend_hash_find(peer_service_mapping, peer_service);
    if (mapped_service_zv) {
        zend_string *mapped_service = zval_get_string(mapped_service_zv);
        add_assoc_str(meta, "peer.service.remapped_from", peer_service);
        add_assoc_str(meta, "peer.service", mapped_service);
        return true;
    }

    return false;
}

void ddtrace_update_root_id_properties(ddtrace_root_span_data *span) {
    zval zv;
    ZVAL_STR(&zv, ddtrace_trace_id_as_hex_string(span->trace_id));
    ddtrace_assign_variable(&span->property_trace_id, &zv);
    if (span->parent_id) {
        ZVAL_STR(&zv, ddtrace_span_id_as_string(span->parent_id));
    } else {
        ZVAL_UNDEF(&zv);
    }
    ddtrace_assign_variable(&span->property_parent_id, &zv);
}

struct superglob_equiv {
    zend_array *server;
    zend_array *post;
};

static void dd_set_entrypoint_root_span_props(struct superglob_equiv *data, ddtrace_root_span_data *span) {
    zend_array *meta = ddtrace_property_array(&span->property_meta);

    if (data->server){
        zend_string *http_url = dd_build_req_url(data->server);
        if (ZSTR_LEN(http_url) > 0) {
            zval http_url_zv;
            ZVAL_STR(&http_url_zv, http_url);
            zend_hash_str_add_new(meta, ZEND_STRL("http.url"), &http_url_zv);
        }
    }

    const char *method = SG(request_info).request_method;
    // run-tests.php sets the env var REQUEST_METHOD, which ends up in $_SERVER
    // To avoid having dozens of tests failing, ignore REQUEST_METHOD if such an env var exists
    static int has_env_req_method;
    if (!has_env_req_method) {
        has_env_req_method = getenv("REQUEST_METHOD") ? 1 : -1;
    }
    if (!method && data->server && has_env_req_method == -1) {
        zval *method_zv = zend_hash_str_find(data->server, ZEND_STRL("REQUEST_METHOD"));
        if (method_zv && Z_TYPE_P(method_zv) == IS_STRING) {
            method = Z_STRVAL_P(method_zv);
        }
    }
    if (method) {
        zval http_method;
        ZVAL_STR(&http_method, zend_string_init(method, strlen(method), 0));
        zend_hash_str_add_new(meta, ZEND_STRL("http.method"), &http_method);

        if (get_DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED()) {
            const char *uri = dd_get_req_uri(data->server);
            zval *prop_resource = &span->property_resource;
            zval_ptr_dtor(prop_resource);
            if (uri) {
                zend_string *path = zend_string_init(uri, strlen(uri), 0);
                zend_string *normalized = ddtrace_uri_normalize_incoming_path(path);
                zend_string *query_string = ZSTR_EMPTY_ALLOC();
                const char *query_str = dd_get_query_string(data->server);
                if (query_str) {
                    query_string = zai_filter_query_string((zai_str)ZAI_STR_FROM_CSTR(query_str),
                                                           get_DD_TRACE_RESOURCE_URI_QUERY_PARAM_ALLOWED(),
                                                           get_DD_TRACE_OBFUSCATION_QUERY_STRING_REGEXP());
                }

                ZVAL_STR(prop_resource,
                         zend_strpprintf(0, "%s %s%s%s", method, ZSTR_VAL(normalized), ZSTR_LEN(query_string) ? "?" : "", ZSTR_VAL(query_string)));
                zend_string_release(query_string);
                zend_string_release(normalized);
                zend_string_release(path);
            } else {
                ZVAL_COPY(prop_resource, &http_method);
            }
        }
    }

    if (get_DD_TRACE_CLIENT_IP_ENABLED() && data->server) {
        zval server_zv;
        ZVAL_ARR(&server_zv, data->server);
        ddtrace_extract_ip_from_headers(&server_zv, meta);
    }

    zend_string *user_agent = dd_get_user_agent(data->server);
    if (user_agent && ZSTR_LEN(user_agent) > 0) {
        zval http_useragent;
        ZVAL_STR_COPY(&http_useragent, user_agent);
        zend_hash_str_add_new(meta, ZEND_STRL("http.useragent"), &http_useragent);
    }

    if (data->server) {
        zend_string *headername;
        zval *headerval;
        ZEND_HASH_FOREACH_STR_KEY_VAL_IND(data->server, headername, headerval) {
            ZVAL_DEREF(headerval);
            if (Z_TYPE_P(headerval) == IS_STRING && headername && ZSTR_LEN(headername) > 5 && memcmp(ZSTR_VAL(headername), "HTTP_", 5) == 0) {
                zend_string *lowerheader = zend_string_init(ZSTR_VAL(headername) + 5, ZSTR_LEN(headername) - 5, 0);
                for (char *ptr = ZSTR_VAL(lowerheader); *ptr; ++ptr) {
                    if (*ptr >= 'A' && *ptr <= 'Z') {
                        *ptr -= 'A' - 'a';
                    } else if (*ptr == '_') {
                        *ptr = '-';
                    }
                }

                dd_add_header_to_meta(meta, "request", lowerheader, Z_STR_P(headerval));
                zend_string_release(lowerheader);
            }
        }
        ZEND_HASH_FOREACH_END();
    }

    if (data->post && zend_hash_num_elements(get_DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED())) {
        zval post_zv;
        ZVAL_ARR(&post_zv, data->post);
        zend_string *empty = ZSTR_EMPTY_ALLOC();
        dd_add_post_fields_to_meta_recursive(meta, "request", empty, &post_zv, get_DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED(), false);
        zend_string_release(empty);
    }
}

void ddtrace_inherit_span_properties(ddtrace_span_data *span, ddtrace_span_data *parent) {
    zval *prop_service = &span->property_service;
    zval_ptr_dtor(prop_service);
    ZVAL_COPY(prop_service, &parent->property_service);
    zval *prop_type = &span->property_type;
    zval_ptr_dtor(prop_type);
    ZVAL_COPY(prop_type, &parent->property_type);

    zend_array *parent_meta = ddtrace_property_array(&parent->property_meta);

    zval *prop_version = &span->property_version;
    zval_ptr_dtor(prop_version);
    zval *version;
    if ((version = zend_hash_str_find(parent_meta, ZEND_STRL("version")))) {
        zval old = *version;
        ddtrace_convert_to_string(version, version);
        zval_ptr_dtor(&old);
    } else {
        version = &parent->property_version;
    }
    ZVAL_COPY(prop_version, version);

    zval *prop_env = &span->property_env;
    zval_ptr_dtor(prop_env);
    zval *env;
    if ((env = zend_hash_str_find(parent_meta, ZEND_STRL("env")))) {
        zval old = *env;
        ddtrace_convert_to_string(env, env);
        zval_ptr_dtor(&old);
    } else {
        env = &parent->property_env;
    }
    ZVAL_COPY(prop_env, env);
}

zend_string *ddtrace_default_service_name(void) {
    if (strcmp(sapi_module.name, "cli") != 0) {
        return zend_string_init(ZEND_STRL("web.request"), 0);
    }

    const char *script_name;
    if (SG(request_info).argc > 0 && (script_name = SG(request_info).argv[0]) && script_name[0] != '\0') {
        return php_basename(script_name, strlen(script_name), NULL, 0);
    } else {
        return zend_string_init(ZEND_STRL("cli.command"), 0);
    }
}

void ddtrace_set_root_span_properties(ddtrace_root_span_data *span) {
    ddtrace_update_root_id_properties(span);

    span->sampling_rule.rule = INT32_MAX;

    zend_array *meta = ddtrace_property_array(&span->property_meta);
    zend_array *metrics = ddtrace_property_array(&span->property_metrics);

    zend_hash_copy(meta, &DDTRACE_G(root_span_tags_preset), (copy_ctor_func_t)zval_add_ref);

    /* Compilers rightfully complain about array bounds due to the struct
     * hack if we write straight to the char storage. Saving the char* to a
     * temporary avoids the warning without a performance penalty.
     */
    zend_string *encoded_id = zend_string_alloc(36, false);
    ddtrace_format_runtime_id((uint8_t(*)[36])&ZSTR_VAL(encoded_id));
    ZSTR_VAL(encoded_id)[36] = '\0';

    zval zv;
    ZVAL_STR(&zv, encoded_id);
    zend_hash_str_add_new(meta, ZEND_STRL("runtime-id"), &zv);

    if (ddtrace_span_is_entrypoint_root(&span->span)) {
        struct superglob_equiv data = {0};
        {
            zval *_server_zv = &PG(http_globals)[TRACK_VARS_SERVER];
            if (Z_TYPE_P(_server_zv) == IS_ARRAY || zend_is_auto_global_str(ZEND_STRL("_SERVER"))) {
                data.server = Z_ARRVAL_P(_server_zv);
            }
        }
        {
            zval *_post_zv = &PG(http_globals)[TRACK_VARS_POST];
            if (Z_TYPE_P(_post_zv) == IS_ARRAY || zend_is_auto_global_str(ZEND_STRL("_POST"))) {
                data.post = Z_ARRVAL_P(_post_zv);
            }
        }

        dd_set_entrypoint_root_span_props(&data, span);
    }

    if (get_DD_TRACE_REPORT_HOSTNAME()) {
#ifndef HOST_NAME_MAX
#define HOST_NAME_MAX 255
#endif

        zend_string *hostname = zend_string_alloc(HOST_NAME_MAX, 0);
        if (gethostname(ZSTR_VAL(hostname), HOST_NAME_MAX + 1)) {
            zend_string_release(hostname);
        } else {
            hostname = zend_string_truncate(hostname, strlen(ZSTR_VAL(hostname)), 0);
            zval hostname_zv;
            ZVAL_STR(&hostname_zv, hostname);
            zend_hash_str_add_new(meta, ZEND_STRL("_dd.hostname"), &hostname_zv);
        }
    }

    ddtrace_root_span_data *parent_root = span->stack->parent_stack->root_span;
    if (parent_root) {
        ddtrace_inherit_span_properties(&span->span, &parent_root->span);
        ZVAL_COPY(&span->property_origin, &parent_root->property_origin);
    } else {
        zval *prop_type = &span->property_type;
        zval *prop_name = &span->property_name;
        zval *prop_service = &span->property_service;
        zval *prop_env = &span->property_env;
        zval *prop_version = &span->property_version;

        if (strcmp(sapi_module.name, "cli") == 0) {
            zval_ptr_dtor(prop_type);
            ZVAL_STR(prop_type, zend_string_init(ZEND_STRL("cli"), 0));
        } else {
            zval_ptr_dtor(prop_type);
            ZVAL_STR(prop_type, zend_string_init(ZEND_STRL("web"), 0));
        }
        zval_ptr_dtor(prop_name);
        ZVAL_STR(prop_name, ddtrace_default_service_name());
        zval_ptr_dtor(prop_service);
        ZVAL_STR_COPY(prop_service, ZSTR_LEN(get_DD_SERVICE()) ? get_DD_SERVICE() : Z_STR_P(prop_name));


        zend_string *version = get_DD_VERSION();
        if (ZSTR_LEN(version) > 0) {  // non-empty
            zval_ptr_dtor(prop_version);
            ZVAL_STR_COPY(prop_version, version);
        }

        zend_string *env = get_DD_ENV();
        if (ZSTR_LEN(env) > 0) {  // non-empty
            zval_ptr_dtor(prop_env);
            ZVAL_STR_COPY(prop_env, env);
        }

        if (DDTRACE_G(dd_origin)) {
            ZVAL_STR_COPY(&span->property_origin, DDTRACE_G(dd_origin));
        }
        if (DDTRACE_G(tracestate)) {
            ZVAL_STR_COPY(&span->property_tracestate, DDTRACE_G(tracestate));
        }

        SEPARATE_ARRAY(&span->property_propagated_tags);
        zend_hash_copy(Z_ARR(span->property_propagated_tags), &DDTRACE_G(propagated_root_span_tags), zval_add_ref);
        SEPARATE_ARRAY(&span->property_tracestate_tags);
        zend_hash_copy(Z_ARR(span->property_tracestate_tags), &DDTRACE_G(tracestate_unknown_dd_keys), zval_add_ref);
        if (DDTRACE_G(propagated_priority_sampling) != DDTRACE_PRIORITY_SAMPLING_UNSET) {
            ZVAL_LONG(&span->property_propagated_sampling_priority, DDTRACE_G(propagated_priority_sampling));
        }
        ZVAL_LONG(&span->property_sampling_priority, DDTRACE_G(default_priority_sampling));

        ddtrace_integration *web_integration = &ddtrace_integrations[DDTRACE_INTEGRATION_WEB];
        if (get_DD_TRACE_ANALYTICS_ENABLED() || web_integration->is_analytics_enabled()) {
            zval sample_rate;
            ZVAL_DOUBLE(&sample_rate, web_integration->get_sample_rate());
            zend_hash_str_add_new(metrics, ZEND_STRL("_dd1.sr.eausr"), &sample_rate);
        }
    }

    zval pid;
    ZVAL_DOUBLE(&pid, (double)getpid());
    zend_hash_str_add_new(metrics, ZEND_STRL("process_id"), &pid);
}

static void _dd_serialize_json(zend_array *arr, smart_str *buf, int options) {
    zval zv;
    ZVAL_ARR(&zv, arr);
    zai_json_encode(buf, &zv, options);
    smart_str_0(buf);
}

static void dd_serialize_array_recursively(zend_array *target, zend_string *str, zval *value, bool convert_to_double) {
    ZVAL_DEREF(value);
    if (Z_TYPE_P(value) == IS_ARRAY || Z_TYPE_P(value) == IS_OBJECT) {
        zend_array *arr;
        if (Z_TYPE_P(value) == IS_OBJECT) {
#if PHP_VERSION_ID >= 70400
            arr = zend_get_properties_for(value, ZEND_PROP_PURPOSE_JSON);
#else
            arr = Z_OBJPROP_P(value);
#endif
        } else {
            arr = Z_ARR_P(value);
        }
        if (zend_hash_num_elements(arr) && !GC_IS_RECURSIVE(arr)) {
            GC_PROTECT_RECURSION(arr);

            zval *val;
            zend_string *str_key;
            zend_ulong num_key;
            ZEND_HASH_FOREACH_KEY_VAL_IND(arr, num_key, str_key, val) {
                zend_string *key;
                if (str_key) {
                    if (ZSTR_VAL(str_key)[0] == '\0' && ZSTR_LEN(str_key) > 0) {
                        // Skip protected and private members
                        continue;
                    }
                    key = zend_strpprintf(0, "%.*s.%.*s", (int)ZSTR_LEN(str), ZSTR_VAL(str), (int)ZSTR_LEN(str_key), ZSTR_VAL(str_key));
                } else {
                    key = zend_strpprintf(0, "%.*s." ZEND_LONG_FMT, (int)ZSTR_LEN(str), ZSTR_VAL(str), num_key);
                }
                dd_serialize_array_recursively(target, key, val, convert_to_double);
                zend_string_release(key);
            } ZEND_HASH_FOREACH_END();

            GC_UNPROTECT_RECURSION(arr);
        } else {
            zval zv;
            ZVAL_EMPTY_STRING(&zv);
            zend_hash_update(target, str, &zv);
        }

#if PHP_VERSION_ID >= 70400
        if (Z_TYPE_P(value) == IS_OBJECT) {
            zend_release_properties(arr);
        }
#endif
    } else if (convert_to_double) {
        zval val_as_double;
        ZVAL_DOUBLE(&val_as_double, zval_get_double(value));
        zend_hash_update(target, str, &val_as_double);
    } else {
        zval val_as_string;
        ddtrace_convert_to_string(&val_as_string, value);
        zend_hash_update(target, str, &val_as_string);
    }
}

static void dd_serialize_array_meta_recursively(zend_array *target, zend_string *str, zval *value) {
    dd_serialize_array_recursively(target, str, value, false);
}

static void dd_serialize_array_metrics_recursively(zend_array *target, zend_string *str, zval *value) {
    dd_serialize_array_recursively(target, str, value, true);
}

static void dd_serialize_array_meta_struct_recursively(zend_array *target, zend_string *str, zval *value) {
    char *data;
    size_t size;

    mpack_writer_t writer;
    mpack_writer_init_growable(&writer, &data, &size);
    int result = msgpack_write_zval(&writer, value, 5);
    mpack_writer_destroy(&writer);

    if (size == 0 || result == 0) {
        return;
    }

    zval serialised;
    ZVAL_STRINGL(&serialised, data, size);

    zend_hash_update(target, str, &serialised);
    free(data);
}

struct iter {
    // caller owns key/value
    bool (*next)(struct iter *self, zend_string **key, zend_string **value);
};
struct iter_llist {
    struct iter parent;
    zend_llist *list;
    zend_llist_position pos;
    sapi_header_struct *cur;
};
static bool dd_iterate_sapi_headers_next(struct iter *self, zend_string **key, zend_string **value) {
    struct iter_llist *iter = (struct iter_llist *)self;

    if (false) {
    next_header:
        iter->cur = zend_llist_get_next_ex(iter->list, &iter->pos);
    }
    if (!iter->cur) {
        return false;
    }

    sapi_header_struct *h = iter->cur;

    if (!h->header_len) {
        goto next_header;
    }

    zend_string *lowerheader = zend_string_alloc(h->header_len, 0);
    char *lowerptr = ZSTR_VAL(lowerheader), *header = h->header, *end = header + h->header_len;
    for (; *header != ':'; ++header, ++lowerptr) {
        if (header >= end) {
            zend_string_release(lowerheader);
            goto next_header;
        }
        *lowerptr = (char)(*header >= 'A' && *header <= 'Z' ? *header - ('A' - 'a') : *header);
    }
    // not actually RFC 7230 compliant (not allowing whitespace there), but most clients accept it. Handle it.
    while (lowerptr > ZSTR_VAL(lowerheader) && isspace(lowerptr[-1])) {
        --lowerptr;
    }
    *lowerptr = 0;
    lowerheader = zend_string_truncate(lowerheader, lowerptr - ZSTR_VAL(lowerheader), 0);
    if (header + 1 < end) {
        ++header;
    }

    while (header < end && isspace(*header)) {
        ++header;
    }
    while (end > header && isspace(end[-1])) {
        --end;
    }

    zend_string *headerval = zend_string_init(header, end - header, 0);
    *key = lowerheader;
    *value = headerval;

    iter->cur = zend_llist_get_next_ex(iter->list, &iter->pos);
    return true;
}
static struct iter *dd_iterate_sapi_headers() {
    struct iter_llist *iter = ecalloc(1, sizeof(struct iter_llist));
    iter->parent.next = dd_iterate_sapi_headers_next, iter->list = &SG(sapi_headers).headers,
    iter->cur = zend_llist_get_first_ex(iter->list, &iter->pos);
    return (struct iter *)iter;
}

struct iter_arr_arr {
    struct iter parent;
    zend_array *arr;
    HashPosition pos;
};
static bool dd_iterate_arr_headers_next(struct iter *self, zend_string **key, zend_string **value)
{
    struct iter_arr_arr *iter = (struct iter_arr_arr *)self;
    zval *v = zend_hash_get_current_data_ex(iter->arr, &iter->pos);
    if (!v) {
        return false;
    }

    zval k_upper_zv;
    zend_string *k;
    zend_hash_get_current_key_zval_ex(iter->arr, &k_upper_zv, &iter->pos);
    if (Z_TYPE(k_upper_zv) == IS_STRING) {
        k = zend_string_tolower(Z_STR(k_upper_zv));
    } else {
        // should not happen
        convert_to_string(&k_upper_zv);
        zend_string *k_upper = Z_STR(k_upper_zv);
        k = zend_string_tolower(k_upper);
    }
    zval_ptr_dtor(&k_upper_zv); // zh_get_current_key_zval_ex copies the str

    *key = k;

    ZVAL_DEREF(v);
    if (Z_TYPE_P(v) != IS_ARRAY) {
        *value = ZSTR_EMPTY_ALLOC(); // should not happen
    } else {
        if (zend_hash_num_elements(Z_ARRVAL_P(v)) == 1) {
            HashPosition pos;
            zend_hash_internal_pointer_reset_ex(Z_ARRVAL_P(v), &pos);
            zval *first = zend_hash_get_current_data_ex(Z_ARRVAL_P(v), &pos);
            if (first && Z_TYPE_P(first) == IS_STRING) {
                *value = Z_STR_P(first);
                zend_string_addref(*value);
            } else {
                *value = ZSTR_EMPTY_ALLOC();  // should not happen
            }
        } else {
            zend_string *delim = zend_string_init(ZEND_STRL(", "), 0);
            zval ret;
            ZVAL_NULL(&ret);
#if PHP_VERSION_ID >= 80000
            php_implode(delim, Z_ARRVAL_P(v), &ret);
#else
            php_implode(delim, v, &ret);
#endif
            zend_string_release(delim);
            if (Z_TYPE(ret) == IS_STRING) {
                *value = Z_STR_P(&ret);
            }
        }
    }

    zend_hash_move_forward_ex(iter->arr, &iter->pos);
    return true;
}

static struct iter *dd_iterate_arr_arr_headers(zend_array *arr) {
    struct iter_arr_arr *iter = ecalloc(1, sizeof(struct iter_arr_arr));
    iter->parent.next = dd_iterate_arr_headers_next;
    iter->arr = arr;
    zend_hash_internal_pointer_reset_ex(arr, &iter->pos);
    return (struct iter *)iter;
}

static void dd_set_entrypoint_root_span_props_end(zend_array *meta, int status, struct iter *headers, bool ignore_error) {
    if (status) {
        zend_string *status_str = zend_long_to_str((long)status);
        zval status_zv;
        ZVAL_STR(&status_zv, status_str);
        zend_hash_str_update(meta, ZEND_STRL("http.status_code"), &status_zv);

        if (status >= 500 && !ignore_error) {
            zval zv = {0}, *value;
            if ((value = zend_hash_str_add(meta, ZEND_STRL("error.type"), &zv))) {
                ZVAL_STR(value, zend_string_init(ZEND_STRL("Internal Server Error"), 0));
            }
        }
    }

    for (zend_string *lowerheader, *headerval; headers->next(headers, &lowerheader, &headerval);) {
        dd_add_header_to_meta(meta, "response", lowerheader, headerval);
        zend_string_release(lowerheader);
        zend_string_release(headerval);
    }
}

static void _serialize_meta(zval *el, ddtrace_span_data *span) {
    bool is_root_span = span->std.ce == ddtrace_ce_root_span_data;
    zval meta_zv, *meta = &span->property_meta;
    bool ignore_error = false;

    array_init(&meta_zv);
    ZVAL_DEREF(meta);
    if (Z_TYPE_P(meta) == IS_ARRAY) {
        zend_string *str_key;
        zval *orig_val;
        ZEND_HASH_FOREACH_STR_KEY_VAL_IND(Z_ARRVAL_P(meta), str_key, orig_val) {
            if (str_key) {
                if (zend_string_equals_literal_ci(str_key, "error.ignored")) {
                    ignore_error = zend_is_true(orig_val);
                    continue;
                }

                dd_serialize_array_meta_recursively(Z_ARRVAL(meta_zv), str_key, orig_val);
            }
        }
        ZEND_HASH_FOREACH_END();
    }
    meta = &meta_zv;

    zval *existing_env, new_env;
    if ((existing_env = zend_hash_str_find(Z_ARRVAL_P(meta), ZEND_STRL("env")))) {
        LOG(DEPRECATED, "Using \"env\" in meta is deprecated. Instead specify the env property directly on the span.");
    } else {
        ddtrace_convert_to_string(&new_env, &span->property_env);
        if (Z_STRLEN(new_env)) {
            existing_env = zend_hash_str_add_new(Z_ARRVAL_P(meta), ZEND_STRL("env"), &new_env);
        } else {
            zval_ptr_dtor(&new_env);
            ZVAL_EMPTY_STRING(&new_env);
            existing_env = &new_env;
        }
    }
    if (!span->parent) {
        if (DDTRACE_G(last_flushed_root_env_name)) {
            zend_string_release(DDTRACE_G(last_flushed_root_env_name));
        }
        DDTRACE_G(last_flushed_root_env_name) = zend_string_copy(Z_STR_P(existing_env));
    }

    zval new_version;
    if (zend_hash_str_exists(Z_ARRVAL_P(meta), ZEND_STRL("version"))) {
        LOG(DEPRECATED, "Using \"version\" in meta is deprecated. Instead specify the version property directly on the span.");
    } else {
        ddtrace_convert_to_string(&new_version, &span->property_version);
        if (Z_STRLEN(new_version)) {
            zend_hash_str_add_new(Z_ARRVAL_P(meta), ZEND_STRL("version"), &new_version);
        } else {
            zval_ptr_dtor(&new_version);
        }
    }

    zval *exception_zv = &span->property_exception;
    bool has_exception = Z_TYPE_P(exception_zv) == IS_OBJECT && instanceof_function(Z_OBJCE_P(exception_zv), zend_ce_throwable);
    if (has_exception) {
        ignore_error = false;
        enum dd_exception exception_type = DD_EXCEPTION_THROWN;
        if (is_root_span) {
            exception_type = Z_PROP_FLAG_P(exception_zv) == 2 ? DD_EXCEPTION_CAUGHT : DD_EXCEPTION_UNCAUGHT;
        }
        ddtrace_exception_to_meta(Z_OBJ_P(exception_zv), meta, dd_add_meta_array, exception_type);
    }

    zend_array *span_links = ddtrace_property_array(&span->property_links);
    if (zend_hash_num_elements(span_links) > 0) {
        // Save the current exception, if any, and clear it for php_json_encode_serializable_object not to fail
        // and zend_call_function to actually call the jsonSerialize method
        // Restored after span links are serialized
        zend_object* current_exception = EG(exception);
        EG(exception) = NULL;

        smart_str buf = {0};
        _dd_serialize_json(span_links, &buf, 0);
        add_assoc_str(meta, "_dd.span_links", buf.s);

        // Restore the exception
        EG(exception) = current_exception;
    }

    if (get_DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED()) { // opt-in
        zend_array *peer_service_sources = ddtrace_property_array(&span->property_peer_service_sources);
        if (zend_hash_str_exists(Z_ARRVAL_P(meta), ZEND_STRL("peer.service"))) { // peer.service is already set by the user, honor it
            add_assoc_str(meta, "_dd.peer.service.source", zend_string_init(ZEND_STRL("peer.service"), 0));

            zval *peer_service = zend_hash_str_find(Z_ARRVAL_P(meta), ZEND_STRL("peer.service"));
            if (peer_service && Z_TYPE_P(peer_service) == IS_STRING) {
                dd_set_mapped_peer_service(meta, Z_STR_P(peer_service));
            }
        } else if (zend_hash_num_elements(peer_service_sources) > 0) {
            zval *tag;
            ZEND_HASH_FOREACH_VAL(peer_service_sources, tag)
            {
                if (Z_TYPE_P(tag) == IS_STRING) { // Use the first tag that is found in the span, if any
                    zval *peer_service = zend_hash_find(Z_ARRVAL_P(meta), Z_STR_P(tag));
                    if (peer_service && Z_TYPE_P(peer_service) == IS_STRING) {
                        add_assoc_str(meta, "_dd.peer.service.source", zend_string_copy(Z_STR_P(tag)));

                        zend_string *peer = zval_get_string(peer_service);
                        if (!dd_set_mapped_peer_service(meta, peer)) {
                            add_assoc_str(meta, "peer.service", peer);
                        }

                        break;
                    }
                }
            }
            ZEND_HASH_FOREACH_END();
        }
    }

    if (ddtrace_span_is_entrypoint_root(span)) {
        int status = SG(sapi_headers).http_response_code;
        if (ddtrace_active_sapi == DATADOG_PHP_SAPI_FRANKENPHP && !status) {
            status = has_exception ? 500 : 200;
        }
        struct iter *headers = dd_iterate_sapi_headers();
        dd_set_entrypoint_root_span_props_end(Z_ARR_P(meta), status, headers, ignore_error);
        efree(headers);
    }

    zval *origin = &span->root->property_origin;
    if (Z_TYPE_P(origin) > IS_NULL && (Z_TYPE_P(origin) != IS_STRING || Z_STRLEN_P(origin))) {
        if (zend_hash_str_add(Z_ARR_P(meta), ZEND_STRL("_dd.origin"), origin)) {
            Z_TRY_ADDREF_P(origin);
        }
    }

    zend_bool error = ddtrace_hash_find_ptr(Z_ARR_P(meta), ZEND_STRL("error.message")) ||
                      ddtrace_hash_find_ptr(Z_ARR_P(meta), ZEND_STRL("error.type"));
    if (error && !ignore_error) {
        add_assoc_long(el, "error", 1);
    }

    if (span->root->trace_id.high && is_root_span) {
        add_assoc_str(meta, "_dd.p.tid", zend_strpprintf(0, "%" PRIx64, span->root->trace_id.high));
    }

    // Add _dd.base_service if service name differs from mapped root service name
    zval prop_service_as_string;
    ddtrace_convert_to_string(&prop_service_as_string, &span->property_service);
    zval prop_root_service_as_string;
    ddtrace_convert_to_string(&prop_root_service_as_string, &span->root->property_service);

    zend_array *service_mappings = get_DD_SERVICE_MAPPING();
    zval *new_root_name = zend_hash_find(service_mappings, Z_STR(prop_root_service_as_string));
    if (new_root_name) {
        zend_string_release(Z_STR(prop_root_service_as_string));
        ZVAL_COPY(&prop_root_service_as_string, new_root_name);
    }

    if (!zend_string_equals_ci(Z_STR(prop_service_as_string), Z_STR(prop_root_service_as_string))) {
        add_assoc_str(meta, "_dd.base_service", Z_STR(prop_root_service_as_string));
    }
    else {
        zend_string_release(Z_STR(prop_root_service_as_string));
    }

    zend_string_release(Z_STR(prop_service_as_string));

    if (zend_array_count(Z_ARRVAL_P(meta))) {
        add_assoc_zval(el, "meta", meta);
    } else {
        zval_ptr_dtor(meta);
    }
}

static HashTable dd_span_sampling_limiters;
#if ZTS
#ifndef _WIN32
static pthread_rwlock_t dd_span_sampling_limiter_lock;
#else
static SRWLOCK dd_span_sampling_limiter_lock;
#endif
#endif

struct dd_sampling_bucket {
    _Atomic(int64_t) hit_count;
    _Atomic(uint64_t) last_update;
};

void ddtrace_clear_span_sampling_limiter(zval *zv) {
    free(Z_PTR_P(zv));
}

void ddtrace_initialize_span_sampling_limiter(void) {
#if ZTS
#ifndef _WIN32
    pthread_rwlock_init(&dd_span_sampling_limiter_lock, NULL);
#else
    InitializeSRWLock(&dd_span_sampling_limiter_lock);
#endif
#endif

    zend_hash_init(&dd_span_sampling_limiters, 8, obsolete, ddtrace_clear_span_sampling_limiter, 1);
}

void ddtrace_shutdown_span_sampling_limiter(void) {
#if ZTS && !defined(_WIN32)
    pthread_rwlock_destroy(&dd_span_sampling_limiter_lock);
#endif

    zend_hash_destroy(&dd_span_sampling_limiters);
}

// ParseBool returns the boolean value represented by the string.
// It accepts 1, t, T, TRUE, true, True, 0, f, F, FALSE, false, False.
// Any other value returns -1.
static zend_always_inline double strconv_parse_bool(zend_string *str) {
    // See Go's strconv.ParseBool
    // https://cs.opensource.google/go/go/+/refs/tags/go1.21.5:src/strconv/atob.go;drc=1f137052e4a20dbd302f947b1cf34cdf4b427d65;l=10
    size_t len = ZSTR_LEN(str);
    if (len == 0) {
        return -1;
    }

    char *s = ZSTR_VAL(str);
    switch (len) {
        case 1:
            switch (s[0]) {
                case '1':
                case 't':
                case 'T':
                    return 1;
                case '0':
                case 'f':
                case 'F':
                    return 0;
            }
            break;
        case 4:
            if (strcmp(s, "TRUE") == 0 || strcmp(s, "True") == 0 || strcmp(s, "true") == 0) {
                return 1;
            }
            break;
        case 5:
            if (strcmp(s, "FALSE") == 0 || strcmp(s, "False") == 0 || strcmp(s, "false") == 0) {
                return 0;
            }
            break;
    }

    return -1;
}

void ddtrace_serialize_span_to_array(ddtrace_span_data *span, zval *array) {
    bool is_root_span = span->std.ce == ddtrace_ce_root_span_data;
    zval *el;
    zval zv;
    el = &zv;
    array_init(el);

    add_assoc_str(el, KEY_TRACE_ID, ddtrace_span_id_as_string(span->root->trace_id.low));
    add_assoc_str(el, KEY_SPAN_ID, zend_string_copy(span->string_id));

    // handle dropped spans
    if (span->parent) {
        ddtrace_span_data *parent = SPANDATA(span->parent);
        // Ensure the parent id is the root span if everything else was dropped
        while (parent->parent && ddtrace_span_is_dropped(parent)) {
            parent = SPANDATA(parent->parent);
        }
        if (parent) {
            add_assoc_str(el, KEY_PARENT_ID, zend_string_copy(parent->string_id));
        }
    } else if (is_root_span) {
        zval *parent_id = &ROOTSPANDATA(&span->std)->property_parent_id;
        if (Z_TYPE_P(parent_id) == IS_STRING) {
            add_assoc_str(el, KEY_PARENT_ID, zend_string_copy(Z_STR_P(parent_id)));
        }
    }
    add_assoc_long(el, "start", span->start);
    add_assoc_long(el, "duration", span->duration);

    zend_array *meta = ddtrace_property_array(&span->property_meta);
    zend_array *metrics = ddtrace_property_array(&span->property_metrics);

    // Remap OTel's status code (metric, http.response.status_code) to DD's status code (meta, http.status_code)
    // OTel HTTP semantic conventions >= 1.21.0
    zval *http_response_status_code = zend_hash_str_find(metrics, ZEND_STRL("http.response.status_code"));
    if (http_response_status_code) {
        Z_TRY_ADDREF_P(http_response_status_code);
        zend_hash_str_update(meta, ZEND_STRL("http.status_code"), http_response_status_code);
        zend_hash_str_del(metrics, ZEND_STRL("http.response.status_code"));
    }

    // Remap OTel's status code (metric, http.status_code) to DD's status code (meta, http.status_code)
    // OTel HTTP semantic conventions < 1.21.0
    zval *http_status_code = zend_hash_str_find(metrics, ZEND_STRL("http.status_code"));
    if (http_status_code) {
        Z_TRY_ADDREF_P(http_status_code);
        zend_hash_str_update(meta, ZEND_STRL("http.status_code"), http_status_code);
        zend_hash_str_del(metrics, ZEND_STRL("http.status_code"));
    }

    // SpanData::$name defaults to fully qualified called name (set at span close)
    zval *operation_name = zend_hash_str_find(meta, ZEND_STRL("operation.name"));
    zval *prop_name = &span->property_name;
    if (operation_name) {
        zend_string *lcname = zend_string_tolower(Z_STR_P(operation_name));
        zval prop_name_as_string;
        ZVAL_STR_COPY(&prop_name_as_string, lcname);
        prop_name = zend_hash_str_update(Z_ARR_P(el), ZEND_STRL("name"), &prop_name_as_string);
        zend_string_release(lcname);
    } else {
        ZVAL_DEREF(prop_name);
        if (Z_TYPE_P(prop_name) > IS_NULL) {
            zval prop_name_as_string;
            ddtrace_convert_to_string(&prop_name_as_string, prop_name);
            prop_name = zend_hash_str_update(Z_ARR_P(el), ZEND_STRL("name"), &prop_name_as_string);
        }
    }

    // SpanData::$resource defaults to SpanData::$name
    zval * resource_name = zend_hash_str_find(meta, ZEND_STRL("resource.name"));
    zval *prop_resource = resource_name ? resource_name : &span->property_resource;
    ZVAL_DEREF(prop_resource);
    zval prop_resource_as_string;
    ZVAL_UNDEF(&prop_resource_as_string);
    if (Z_TYPE_P(prop_resource) > IS_FALSE && (Z_TYPE_P(prop_resource) != IS_STRING || Z_STRLEN_P(prop_resource) > 0)) {
        ddtrace_convert_to_string(&prop_resource_as_string, prop_resource);
    } else if (Z_TYPE_P(prop_name) > IS_NULL) {
        ZVAL_COPY(&prop_resource_as_string, prop_name);
    }

    if (Z_TYPE(prop_resource_as_string) == IS_STRING) {
        _add_assoc_zval_copy(el, "resource", &prop_resource_as_string);
    }

    if (resource_name) {
        zend_hash_str_del(meta, ZEND_STRL("resource.name"));
    }

    // TODO: SpanData::$service defaults to parent SpanData::$service or DD_SERVICE if root span
    zval *service_name = zend_hash_str_find(meta, ZEND_STRL("service.name"));
    zval *prop_service = &span->property_service;
    ZVAL_DEREF(prop_service);
    zval prop_service_as_string;
    ZVAL_UNDEF(&prop_service_as_string);
    if (service_name) {
        ddtrace_convert_to_string(&prop_service_as_string, service_name);
    } else if (Z_TYPE_P(prop_service) > IS_NULL) {
        ddtrace_convert_to_string(&prop_service_as_string, prop_service);
    }

    if (Z_TYPE(prop_service_as_string) == IS_STRING) {
        zend_array *service_mappings = get_DD_SERVICE_MAPPING();
        zval *new_name = zend_hash_find(service_mappings, Z_STR(prop_service_as_string));
        if (new_name) {
            zend_string_release(Z_STR(prop_service_as_string));
            ZVAL_COPY(&prop_service_as_string, new_name);
        }

        if (!span->parent) {
            if (DDTRACE_G(last_flushed_root_service_name)) {
                zend_string_release(DDTRACE_G(last_flushed_root_service_name));
            }
            DDTRACE_G(last_flushed_root_service_name) = zend_string_copy(Z_STR(prop_service_as_string));
        }

        add_assoc_zval(el, "service", &prop_service_as_string);
    }

    if (service_name) {
        zend_hash_str_del(meta, ZEND_STRL("service.name"));
    }

    // SpanData::$type is optional and defaults to 'custom' at the Agent level
    zval *span_type = zend_hash_str_find(meta, ZEND_STRL("span.type"));
    zval *prop_type = span_type ? span_type : &span->property_type;
    ZVAL_DEREF(prop_type);
    zval prop_type_as_string;
    ZVAL_UNDEF(&prop_type_as_string);
    if (Z_TYPE_P(prop_type) > IS_NULL) {
        ddtrace_convert_to_string(&prop_type_as_string, prop_type);
        _add_assoc_zval_copy(el, "type", &prop_type_as_string);
    }

    if (span_type) {
        zend_hash_str_del(meta, ZEND_STRL("span.type"));
    }

    zval *analytics_event = zend_hash_str_find(meta, ZEND_STRL("analytics.event"));
    if (analytics_event) {
        zval analytics_event_as_double;
        if (Z_TYPE_P(analytics_event) == IS_STRING) {
            double parsed_analytics_event = strconv_parse_bool(Z_STR_P(analytics_event));
            if (parsed_analytics_event >= 0) {
                ZVAL_DOUBLE(&analytics_event_as_double, parsed_analytics_event);
                zend_hash_str_add_new(metrics, ZEND_STRL("_dd1.sr.eausr"), &analytics_event_as_double);
            }
        } else {
            ZVAL_DOUBLE(&analytics_event_as_double, zval_get_double(analytics_event));
            zend_hash_str_add_new(metrics, ZEND_STRL("_dd1.sr.eausr"), &analytics_event_as_double);
        }
        zend_hash_str_del(meta, ZEND_STRL("analytics.event"));
    }

    // Notify profiling for Endpoint Profiling.
    if (profiling_notify_trace_finished && ddtrace_span_is_entrypoint_root(span) && Z_TYPE(prop_resource_as_string) == IS_STRING) {
        zai_str type = ZAI_STRL("custom");
        if (Z_TYPE(prop_type_as_string) == IS_STRING) {
            type = (zai_str) ZAI_STR_FROM_ZSTR(Z_STR(prop_type_as_string));
        }
        zai_str resource = (zai_str)ZAI_STR_FROM_ZSTR(Z_STR(prop_resource_as_string));
        LOG(WARN, "Notifying profiler of finished local root span.");
        profiling_notify_trace_finished(span->span_id, type, resource);
    }

    zval_ptr_dtor(&prop_type_as_string);
    zval_ptr_dtor(&prop_resource_as_string);

    if (zend_hash_num_elements(get_DD_SPAN_SAMPLING_RULES()) && ddtrace_fetch_priority_sampling_from_span(span->root) <= 0) {
        zval *rule;
        ZEND_HASH_FOREACH_VAL(get_DD_SPAN_SAMPLING_RULES(), rule) {
            if (Z_TYPE_P(rule) != IS_ARRAY) {
                continue;
            }

            bool rule_matches = true;

            zval *rule_service;
            if ((rule_service = zend_hash_str_find(Z_ARR_P(rule), ZEND_STRL("service")))) {
                if (Z_TYPE_P(prop_service) > IS_NULL) {
                    rule_matches &= dd_glob_rule_matches(rule_service, Z_STR(prop_service_as_string));
                } else {
                    rule_matches &= false;
                }
            }
            zval *rule_name;
            if ((rule_name = zend_hash_str_find(Z_ARR_P(rule), ZEND_STRL("name")))) {
                if (Z_TYPE_P(prop_name) > IS_NULL) {
                    rule_matches &= dd_glob_rule_matches(rule_name, Z_STR_P(prop_name));
                } else {
                    rule_matches = false;
                }
            }

            if (!rule_matches) {
                continue;
            }

            zval *sample_rate_zv;
            double sample_rate = 1;
            if ((sample_rate_zv = zend_hash_str_find(Z_ARR_P(rule), ZEND_STRL("sample_rate")))) {
                sample_rate = zval_get_double(sample_rate_zv);
                if ((double)span->span_id > sample_rate * (double)~0ULL) {
                    break; // sample_rate not matched
                }
            }

            zval *max_per_second_zv;
            double max_per_second = 0;
            if ((max_per_second_zv = zend_hash_str_find(Z_ARR_P(rule), ZEND_STRL("max_per_second")))) {
                max_per_second = zval_get_double(max_per_second_zv);
                size_t service_pattern_len = rule_service ? Z_STRLEN_P(rule_service) : 0;
                zend_string *rule_key = zend_string_alloc(service_pattern_len + (rule_name ? Z_STRLEN_P(rule_name) + (service_pattern_len != 0) : 0), 1);
                if (rule_service) {
                    memcpy(ZSTR_VAL(rule_key), Z_STRVAL_P(rule_service), Z_STRLEN_P(rule_service));
                }
                if (rule_name) {
                    ZSTR_VAL(rule_key)[service_pattern_len] = 0;
                    memcpy(ZSTR_VAL(rule_key) + service_pattern_len + 1, Z_STRVAL_P(rule_name), Z_STRLEN_P(rule_name));
                }
                ZSTR_VAL(rule_key)[ZSTR_LEN(rule_key)] = 0;

#if ZTS
#ifndef _WIN32
                pthread_rwlock_rdlock(&dd_span_sampling_limiter_lock);
#else
                AcquireSRWLockShared(&dd_span_sampling_limiter_lock);
#endif
#endif
                struct dd_sampling_bucket *sampling_bucket = zend_hash_find_ptr(&dd_span_sampling_limiters, rule_key);
#if ZTS
#ifndef _WIN32
                pthread_rwlock_unlock(&dd_span_sampling_limiter_lock);
#else
                ReleaseSRWLockShared(&dd_span_sampling_limiter_lock);
#endif
#endif

                uint64_t timeval = zend_hrtime();

                if (!sampling_bucket) {
                    struct dd_sampling_bucket *new_sampling_bucket = malloc(sizeof(*new_sampling_bucket));
                    new_sampling_bucket->hit_count = 1;
                    new_sampling_bucket->last_update = timeval;

#if ZTS
#ifndef _WIN32
                    pthread_rwlock_wrlock(&dd_span_sampling_limiter_lock);
#else
                    AcquireSRWLockExclusive(&dd_span_sampling_limiter_lock);
#endif
#endif
                    if (!zend_hash_add_ptr(&dd_span_sampling_limiters, rule_key, new_sampling_bucket)) {
                        free(new_sampling_bucket);
                        sampling_bucket = zend_hash_find_ptr(&dd_span_sampling_limiters, rule_key);
                    }
#if ZTS
#ifndef _WIN32
                    pthread_rwlock_unlock(&dd_span_sampling_limiter_lock);
#else
                    ReleaseSRWLockExclusive(&dd_span_sampling_limiter_lock);
#endif
#endif
                }

                zend_string_release(rule_key);

                if (sampling_bucket) {
                    // restore allowed time basis
                    uint64_t old_time = atomic_exchange(&sampling_bucket->last_update, timeval);
                    int64_t clear_counter = (int64_t)((long double)(timeval - old_time) * max_per_second);

                    int64_t previous_hits = atomic_fetch_sub(&sampling_bucket->hit_count, clear_counter);
                    if (previous_hits < clear_counter) {
                        atomic_fetch_add(&sampling_bucket->hit_count, previous_hits > 0 ? clear_counter - previous_hits : clear_counter);
                    }

                    previous_hits = atomic_fetch_add(&sampling_bucket->hit_count, ZEND_NANO_IN_SEC);
                    if ((long double)previous_hits / ZEND_NANO_IN_SEC >= max_per_second) {
                        atomic_fetch_sub(&sampling_bucket->hit_count, ZEND_NANO_IN_SEC);
                        break; // limit exceeded
                    }
                }
            }

            zval mechanism;
            ZVAL_LONG(&mechanism, 8);
            zend_hash_str_update(metrics, ZEND_STRL("_dd.span_sampling.mechanism"), &mechanism);

            zval rule_rate;
            ZVAL_DOUBLE(&rule_rate, sample_rate);
            zend_hash_str_update(metrics, ZEND_STRL("_dd.span_sampling.rule_rate"), &rule_rate);

            if (max_per_second_zv) {
                zval max_per_sec;
                ZVAL_DOUBLE(&max_per_sec, max_per_second);
                zend_hash_str_update(metrics, ZEND_STRL("_dd.span_sampling.max_per_second"), &max_per_sec);
            }

            break;
        }
        ZEND_HASH_FOREACH_END();
    }

    if (operation_name) {
        zend_hash_str_del(meta, ZEND_STRL("operation.name"));
    }

    _serialize_meta(el, span);


    zval metrics_zv;
    array_init(&metrics_zv);
    zend_string *str_key;
    zval *val;
    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(metrics, str_key, val) {
        if (str_key) {
            dd_serialize_array_metrics_recursively(Z_ARRVAL(metrics_zv), str_key, val);
        }
    } ZEND_HASH_FOREACH_END();

    if (is_root_span) {
        if (Z_TYPE_P(&span->root->property_sampling_priority) != IS_UNDEF) {
            add_assoc_double(&metrics_zv, "_sampling_priority_v1", zval_get_long(&span->root->property_sampling_priority));
        }
    }

    if (ddtrace_span_is_entrypoint_root(span) && get_DD_TRACE_MEASURE_COMPILE_TIME()) {
        add_assoc_double(&metrics_zv, "php.compilation.total_time_ms", ddtrace_compile_time_get() / 1000.);
    }

    LOGEV(SPAN, {
        zend_string *key;
        zval *tag_zv;
        zval *serialized_meta = zend_hash_str_find(Z_ARR_P(el), ZEND_STRL("meta"));
        smart_str meta_str = {0};
        if (serialized_meta) {
            ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARR_P(serialized_meta), key, tag_zv) {
                if (meta_str.s) {
                    smart_str_appends(&meta_str, ", ");
                }
                smart_str_append(&meta_str, key);
                smart_str_appends(&meta_str, "='");
                smart_str_append(&meta_str, Z_STR_P(tag_zv));
                smart_str_appendc(&meta_str, '\'');
            } ZEND_HASH_FOREACH_END();
            smart_str_0(&meta_str);
        }
        smart_str metrics_str = {0};
        if (zend_hash_num_elements(Z_ARR(metrics_zv))) {
            ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARR(metrics_zv), key, tag_zv) {
                if (metrics_str.s) {
                    smart_str_appends(&metrics_str, ", ");
                }
                smart_str_append(&metrics_str, key);
                smart_str_appends(&metrics_str, "='");
                smart_str_append_double(&metrics_str, Z_DVAL_P(tag_zv), 12, false);
                smart_str_appendc(&metrics_str, '\'');
            } ZEND_HASH_FOREACH_END();
            smart_str_0(&metrics_str);
        }
        prop_name = zend_hash_str_find(Z_ARR_P(el), ZEND_STRL("name")); // refetch, array may have been rehashed
        log("Encoding span %" PRIu64 ": trace_id=%s, name='%s', service='%s', resource: '%s', type '%s' with tags: %s; and metrics: %s",
            span->span_id,
            Z_STRVAL(span->root->property_trace_id), Z_TYPE_P(prop_name) == IS_STRING ? Z_STRVAL_P(prop_name) : "",
            Z_TYPE(prop_service_as_string) == IS_STRING ? Z_STRVAL(prop_service_as_string) : "",
            Z_TYPE(prop_resource_as_string) == IS_STRING ? Z_STRVAL(prop_resource_as_string) : "",
            Z_TYPE(prop_type_as_string) == IS_STRING ? Z_STRVAL(prop_type_as_string) : "",
            meta_str.s ? ZSTR_VAL(meta_str.s) : "-",
            metrics_str.s ? ZSTR_VAL(metrics_str.s) : "-");
        smart_str_free(&meta_str);
        smart_str_free(&metrics_str);
    })

    if (zend_hash_num_elements(Z_ARR(metrics_zv))) {
        zend_hash_str_add_new(Z_ARR_P(el), ZEND_STRL("metrics"), &metrics_zv);
    } else {
        zend_array_destroy(Z_ARR(metrics_zv));
    }

    zend_array *meta_struct = ddtrace_property_array(&span->property_meta_struct);
    zval meta_struct_zv;
    array_init(&meta_struct_zv);
    zend_string *ms_str_key;
    zval *ms_val;
    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(meta_struct, ms_str_key, ms_val) {
        if (ms_str_key) {
            dd_serialize_array_meta_struct_recursively(Z_ARRVAL(meta_struct_zv), ms_str_key, ms_val);
        }
    }
    ZEND_HASH_FOREACH_END();
    if (zend_hash_num_elements(Z_ARR(meta_struct_zv))) {
        zend_hash_str_add_new(Z_ARR_P(el), ZEND_STRL("meta_struct"), &meta_struct_zv);
    } else {
        zend_array_destroy(Z_ARR(meta_struct_zv));
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

void ddtrace_save_active_error_to_metadata(void) {
    if (!DDTRACE_G(active_error).type || !DDTRACE_G(active_stack)) {
        return;
    }

    dd_error_info error = {
        .type = dd_error_type(DDTRACE_G(active_error).type),
        .msg = zend_string_copy(DDTRACE_G(active_error).message),
        .stack = dd_fatal_error_stack(),
    };
    for (ddtrace_span_properties *pspan = ddtrace_active_span_props(); pspan; pspan = pspan->parent) {
        if (Z_TYPE(pspan->property_exception) == IS_OBJECT) {  // exceptions take priority
            continue;
        }

        dd_fatal_error_to_meta(ddtrace_property_array(&pspan->property_meta), error);
    }
    zend_string_release(error.type);
    zend_string_release(error.msg);
    if (error.stack) {
        zend_string_release(error.stack);
    }
}

static void clear_last_error(void) {
    if (PG(last_error_message)) {
#if PHP_VERSION_ID < 80000
        free(PG(last_error_message));
#else
        zend_string_release(PG(last_error_message));
#endif
        PG(last_error_message) = NULL;
    }
    if (PG(last_error_file)) {
#if PHP_VERSION_ID < 80100
        free(PG(last_error_file));
#else
        zend_string_release(PG(last_error_file));
#endif
        PG(last_error_file) = NULL;
    }
}

void ddtrace_error_cb(DDTRACE_ERROR_CB_PARAMETERS) {
    // We need the error handling to place nicely with the sandbox. Our choice here is to skip any error handling if the sandbox is active.
    // We just save the error for later handling by sandbox error reporting functionality.
    // On fatal error we explicitly bail out.
    bool is_fatal_error = orig_type & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR);
    if (zai_sandbox_active) {
        // Do not track silenced errors like via @ operator
        if (!is_fatal_error && (orig_type & EG(error_reporting)) == 0) {
            return;
        }

        clear_last_error();
        PG(last_error_type) = orig_type & E_ALL;
#if PHP_VERSION_ID < 80000
        char *buf;
        // vsssprintf uses Zend allocator, but PG(last_error_message) must be malloc() memory
        vspprintf(&buf, PG(log_errors_max_len), format, args);
        PG(last_error_message) = strdup(buf);
        efree(buf);
#else
        PG(last_error_message) = zend_string_copy(message);
#endif
#if PHP_VERSION_ID < 80100
        if (!error_filename) {
            error_filename = "Unknown";
        }
        PG(last_error_file) = strdup(error_filename);
#else
        if (!error_filename) {
            error_filename = ZSTR_KNOWN(ZEND_STR_UNKNOWN_CAPITALIZED);
        }
        PG(last_error_file) = zend_string_copy(error_filename);
#endif
        PG(last_error_lineno) = (int)error_lineno;

        if (is_fatal_error) {
            zend_bailout();
        }
        return;
    }

    // If this is a fatal error we have to handle it early. These are always bailing out, independently of the configured EG(error_handling) mode.
    if (EXPECTED(EG(active)) && UNEXPECTED(is_fatal_error)) {
        /* If there is a fatal error in shutdown then this might not be an array
         * because we set it to IS_NULL in RSHUTDOWN. We probably want a more
         * robust way of detecting this, but I'm not sure how yet.
         */
        if (DDTRACE_G(active_stack)) {
#if PHP_VERSION_ID < 80000
            va_list arg_copy;
            va_copy(arg_copy, args);
            zend_string *message = zend_vstrpprintf(0, format, arg_copy);
            va_end(arg_copy);
#endif
            dd_error_info error = {
                .type = dd_error_type(orig_type),
                .msg = dd_truncate_uncaught_exception(message),
                .stack = dd_fatal_error_stack(),
            };
#if PHP_VERSION_ID < 80000
            zend_string_release(message);
#endif
            ddtrace_span_properties *pspan;
            for (pspan = DDTRACE_G(active_stack)->active; pspan; pspan = pspan->parent) {
                if (Z_TYPE(pspan->property_exception) > IS_FALSE) {
                    continue;
                }

                dd_fatal_error_to_meta(ddtrace_property_array(&pspan->property_meta), error);
            }
            zend_string_release(error.type);
            zend_string_release(error.msg);
            if (error.stack) {
                zend_string_release(error.stack);
            }
        }
    }

    ddtrace_prev_error_cb(DDTRACE_ERROR_CB_PARAM_PASSTHRU);
}

static zend_array *dd_ser_start_user_req(ddtrace_user_req_listeners *self, zend_object *span, zend_array *variables, zval *entity) {
    UNUSED(self);
    UNUSED(entity);

    struct superglob_equiv data = {0};
    zval *_server_zv = zend_hash_str_find(variables, ZEND_STRL("_SERVER"));
    if (_server_zv && Z_TYPE_P(_server_zv) == IS_ARRAY) {
        data.server = Z_ARRVAL_P(_server_zv);
    }

    zval *_post_zv = zend_hash_str_find(variables, ZEND_STRL("_POST"));
    if (_post_zv && Z_TYPE_P(_post_zv) == IS_ARRAY) {
        data.post = Z_ARRVAL_P(_post_zv);
    }

    if (_server_zv || _post_zv) {
        dd_set_entrypoint_root_span_props(&data, ROOTSPANDATA(span));
    }

    return NULL;
}

static zend_array *dd_ser_response_committed(ddtrace_user_req_listeners *self, zend_object *span, int status, zend_array *headers, zval *entity) {
    UNUSED(self, entity);

    ddtrace_root_span_data *root_span_data = ROOTSPANDATA(span);
    zend_array *meta = ddtrace_property_array(&root_span_data->property_meta);
    struct iter *iter = dd_iterate_arr_arr_headers(headers);
    dd_set_entrypoint_root_span_props_end(meta, status, iter, false);
    efree(iter);
    return NULL;
}

static void dd_ser_finish_user_req(ddtrace_user_req_listeners *self, zend_object *span) {
    UNUSED(self, span);
}

static ddtrace_user_req_listeners ser_user_req_listeners = {
    .priority = INT_MAX,
    .start_user_req = dd_ser_start_user_req,
    .response_committed = dd_ser_response_committed,
    .finish_user_req = dd_ser_finish_user_req,
};

void ddtrace_serializer_startup()
{
    ddtrace_user_req_add_listeners(&ser_user_req_listeners);
}
