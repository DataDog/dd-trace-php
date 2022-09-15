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
// comment to prevent clang from reordering these headers
#include <SAPI.h>
#include <exceptions/exceptions.h>
#include <stdatomic.h>
#include <zai_string/string.h>

#include "arrays.h"
#include "compat_string.h"
#include "ddtrace.h"
#include "engine_api.h"
#include "engine_hooks.h"
#include "ip_extraction.h"
#include "logging.h"
#include "mpack/mpack.h"
#include "priority_sampling/priority_sampling.h"
#include "runtime.h"
#include "span.h"
#include "uri_normalization.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

extern void (*profiling_notify_trace_finished)(uint64_t local_root_span_id,
                                               zai_string_view span_type,
                                               zai_string_view resource);

#define MAX_ID_BUFSIZ 21  // 1.8e^19 = 20 chars + 1 terminator
#define KEY_TRACE_ID "trace_id"
#define KEY_SPAN_ID "span_id"
#define KEY_PARENT_ID "parent_id"

static int msgpack_write_zval(mpack_writer_t *writer, zval *trace, int level);

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
        if (is_assoc == 1) {
            char num_str_buf[MAX_ID_BUFSIZ], *key;
            if (string_key) {
                key = ZSTR_VAL(string_key);
            } else {
                key = num_str_buf;
                sprintf(num_str_buf, ZEND_LONG_FMT, num_key);
            }
            mpack_write_cstr(writer, key);
            // If the key is trace_id, span_id or parent_id then strings have to be converted to uint64 when packed.
            if (level <= 3 &&
                (0 == strcmp(KEY_TRACE_ID, key) || 0 == strcmp(KEY_SPAN_ID, key) || 0 == strcmp(KEY_PARENT_ID, key))) {
                zval_string_as_uint64 = true;
            }
        }

        // Writing the value
        if (zval_string_as_uint64) {
            mpack_write_u64(writer, strtoull(Z_STRVAL_P(tmp), NULL, 10));
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

static zend_result dd_exception_to_error_msg(zend_object *exception, void *context, add_tag_fn_t add_tag) {
    zend_string *msg = zai_exception_message(exception);
    zend_long line = zval_get_long(ZAI_EXCEPTION_PROPERTY(exception, ZEND_STR_LINE));
    zend_string *file = ddtrace_convert_to_str(ZAI_EXCEPTION_PROPERTY(exception, ZEND_STR_FILE));

    char *error_text, *status_line;
    zend_bool caught = SG(sapi_headers).http_response_code >= 500;

    if (caught) {
        if (SG(sapi_headers).http_status_line) {
            asprintf(&status_line, " (%s)", SG(sapi_headers).http_status_line);
        } else {
            asprintf(&status_line, " (%d)", SG(sapi_headers).http_response_code);
        }
    }

    int error_len = asprintf(&error_text, "%s %s%s%s%.*s in %s:" ZEND_LONG_FMT, caught ? "Caught" : "Uncaught",
                             ZSTR_VAL(exception->ce->name), caught ? status_line : "", ZSTR_LEN(msg) > 0 ? ": " : "",
                             (int)ZSTR_LEN(msg), ZSTR_VAL(msg), ZSTR_VAL(file), line);

    if (caught) {
        free(status_line);
    }

    ddtrace_string key = DDTRACE_STRING_LITERAL("error.msg");
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

static zend_result dd_exception_trace_to_error_stack(zend_string *trace, void *context, add_tag_fn_t add_tag) {
    ddtrace_string key = DDTRACE_STRING_LITERAL("error.stack");
    ddtrace_string value = {ZSTR_VAL(trace), ZSTR_LEN(trace)};
    zend_result result = add_tag(context, key, value);
    zend_string_release(trace);
    return result;
}

// Guarantees that add_tag will only be called once per tag, will stop trying to add tags if one fails.
static zend_result ddtrace_exception_to_meta(zend_object *exception, void *context, add_tag_fn_t add_meta) {
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

    bool success = dd_exception_to_error_msg(exception, context, add_meta) == SUCCESS &&
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
        zend_symtable_str_update(meta, ZEND_STRL("error.msg"), &tmp);
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

void ddtrace_set_global_span_properties(ddtrace_span_t *span) {
    zend_array *meta = ddtrace_spandata_property_meta(span);
    zval value;

    zend_string *version = get_DD_VERSION();
    if (ZSTR_LEN(version) > 0) {  // non-empty
        ZVAL_STR_COPY(&value, version);
        zend_hash_str_add_new(meta, ZEND_STRL("version"), &value);
    }

    zend_string *env = get_DD_ENV();
    if (ZSTR_LEN(env) > 0) {  // non-empty
        ZVAL_STR_COPY(&value, env);
        zend_hash_str_add_new(meta, ZEND_STRL("env"), &value);
    }

    if (DDTRACE_G(dd_origin)) {
        ZVAL_STR_COPY(&value, DDTRACE_G(dd_origin));
        zend_hash_str_add_new(meta, ZEND_STRL("_dd.origin"), &value);
    }

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

    ZVAL_STR(ddtrace_spandata_property_id(span), zend_strpprintf(DD_TRACE_MAX_ID_LEN, "%" PRIu64, span->span_id));
}

static const char *dd_get_req_uri() {
    const char *uri = NULL;
    zval *_server = &PG(http_globals)[TRACK_VARS_SERVER];
    if (Z_TYPE_P(_server) == IS_ARRAY || zend_is_auto_global_str(ZEND_STRL("_SERVER"))) {
        zval *req_uri = zend_hash_str_find(Z_ARRVAL_P(_server), ZEND_STRL("REQUEST_URI"));
        if (req_uri && Z_TYPE_P(req_uri) == IS_STRING) {
            uri = Z_STRVAL_P(req_uri);
        }
    }

    if (!uri) {
        uri = SG(request_info).request_uri;
    }

    return uri;
}

static const char *dd_get_query_string() {
    const char *query_string = NULL;
    zval *_server = &PG(http_globals)[TRACK_VARS_SERVER];
    if (Z_TYPE_P(_server) == IS_ARRAY || zend_is_auto_global_str(ZEND_STRL("_SERVER"))) {
        zval *query_str = zend_hash_str_find(Z_ARRVAL_P(_server), ZEND_STRL("QUERY_STRING"));
        if (query_str && Z_TYPE_P(query_str) == IS_STRING) {
            query_string = Z_STRVAL_P(query_str);
        }
    }

    if (!query_string) {
        query_string = SG(request_info).query_string;
    }

    return query_string;
}

static zend_string *dd_build_req_url() {
    zval *_server = &PG(http_globals)[TRACK_VARS_SERVER];
    const char *uri = dd_get_req_uri();
    if (!uri) {
        return ZSTR_EMPTY_ALLOC();
    }

    zend_bool is_https = zend_hash_str_exists(Z_ARRVAL_P(_server), ZEND_STRL("HTTPS"));

    zval *host_zv;
    if ((!(host_zv = zend_hash_str_find(Z_ARRVAL_P(_server), ZEND_STRL("HTTP_HOST"))) &&
         !(host_zv = zend_hash_str_find(Z_ARRVAL_P(_server), ZEND_STRL("SERVER_NAME")))) ||
        Z_TYPE_P(host_zv) != IS_STRING) {
        return ZSTR_EMPTY_ALLOC();
    }

    int uri_len;
    char *question_mark = strchr(uri, '?');
    zend_string *query_string = ZSTR_EMPTY_ALLOC();
    if (question_mark) {
        uri_len = question_mark - uri;
        query_string = zai_filter_query_string(
            (zai_string_view){.len = strlen(uri) - uri_len - 1, .ptr = question_mark + 1},
            get_DD_TRACE_HTTP_URL_QUERY_PARAM_ALLOWED(), get_DD_TRACE_OBFUSCATION_QUERY_STRING_REGEXP());
    } else {
        uri_len = strlen(uri);
    }

    zend_string *url =
        zend_strpprintf(0, "http%s://%s%.*s%s%.*s", is_https ? "s" : "", Z_STRVAL_P(host_zv), uri_len, uri,
                        ZSTR_LEN(query_string) ? "?" : "", (int)ZSTR_LEN(query_string), ZSTR_VAL(query_string));

    zend_string_release(query_string);

    return url;
}

static zend_string *dd_get_user_agent() {
    zval *_server = &PG(http_globals)[TRACK_VARS_SERVER];
    if (Z_TYPE_P(_server) == IS_ARRAY || zend_is_auto_global_str(ZEND_STRL("_SERVER"))) {
        zval *user_agent = zend_hash_str_find(Z_ARRVAL_P(_server), ZEND_STRL("HTTP_USER_AGENT"));
        if (user_agent && Z_TYPE_P(user_agent) == IS_STRING) {
            return Z_STR_P(user_agent);
        }
    }
    return ZSTR_EMPTY_ALLOC();
}

void ddtrace_set_root_span_properties(ddtrace_span_t *span) {
    zend_array *meta = ddtrace_spandata_property_meta(span);

    zend_hash_copy(meta, &DDTRACE_G(root_span_tags_preset), (copy_ctor_func_t)zval_add_ref);

    zval pid;
    ZVAL_LONG(&pid, (long)getpid());
    zend_hash_str_add_new(meta, ZEND_STRL("system.pid"), &pid);

    datadog_php_uuid runtime_id = ddtrace_profiling_runtime_id();
    if (!datadog_php_uuid_is_nil(runtime_id)) {
        zend_string *encoded_id = zend_string_alloc(36, false);

        /* Compilers rightfully complain about array bounds due to the struct
         * hack if we write straight to the char storage. Saving the char* to a
         * temporary avoids the warning without a performance penalty.
         */
        char *tmp = ZSTR_VAL(encoded_id);
        datadog_php_uuid_encode36(runtime_id, tmp);
        ZSTR_VAL(encoded_id)[36] = '\0';

        zval zv;
        ZVAL_STR(&zv, encoded_id);
        zend_hash_str_add_new(meta, ZEND_STRL("runtime-id"), &zv);
    }

    zval http_url;
    ZVAL_STR(&http_url, dd_build_req_url());
    if (Z_STRLEN(http_url)) {
        zend_hash_str_add_new(meta, ZEND_STRL("http.url"), &http_url);
    }

    const char *method = SG(request_info).request_method;
    if (method) {
        zval http_method;
        ZVAL_STR(&http_method, zend_string_init(method, strlen(method), 0));
        zend_hash_str_add_new(meta, ZEND_STRL("http.method"), &http_method);

        if (get_DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED()) {
            const char *uri = dd_get_req_uri();
            zval *prop_resource = ddtrace_spandata_property_resource(span);
            if (uri) {
                zend_string *path = zend_string_init(uri, strlen(uri), 0);
                zend_string *normalized = ddtrace_uri_normalize_incoming_path(path);
                zend_string *query_string = ZSTR_EMPTY_ALLOC();
                const char *query_str = dd_get_query_string();
                if (query_str) {
                    query_string =
                        zai_filter_query_string((zai_string_view){.len = strlen(query_str), .ptr = query_str},
                                                get_DD_TRACE_RESOURCE_URI_QUERY_PARAM_ALLOWED(),
                                                get_DD_TRACE_OBFUSCATION_QUERY_STRING_REGEXP());
                }

                ZVAL_STR(prop_resource, zend_strpprintf(0, "%s %s%s%.*s", method, ZSTR_VAL(normalized),
                                                        ZSTR_LEN(query_string) ? "?" : "", (int)ZSTR_LEN(query_string),
                                                        ZSTR_VAL(query_string)));
                zend_string_release(query_string);
                zend_string_release(normalized);
                zend_string_release(path);
            } else {
                ZVAL_COPY(prop_resource, &http_method);
            }
        }
    }

    if (!get_DD_TRACE_CLIENT_IP_HEADER_DISABLED()) {
        if (Z_TYPE(PG(http_globals)[TRACK_VARS_SERVER]) == IS_ARRAY || zend_is_auto_global_str(ZEND_STRL("_SERVER"))) {
            ddtrace_extract_ip_from_headers(&PG(http_globals)[TRACK_VARS_SERVER], meta);
        }
    }

    zend_string *user_agent = dd_get_user_agent();
    if (user_agent && ZSTR_LEN(user_agent) > 0) {
        zval http_useragent;
        ZVAL_STR_COPY(&http_useragent, user_agent);
        zend_hash_str_add_new(meta, ZEND_STRL("http.useragent"), &http_useragent);
    }

    zval *prop_type = ddtrace_spandata_property_type(span);
    zval *prop_name = ddtrace_spandata_property_name(span);
    if (strcmp(sapi_module.name, "cli") == 0) {
        ZVAL_STR(prop_type, zend_string_init(ZEND_STRL("cli"), 0));
        const char *script_name;
        ZVAL_STR(prop_name,
            (SG(request_info).argc > 0 && (script_name = SG(request_info).argv[0]) && script_name[0] != '\0')
                ? php_basename(script_name, strlen(script_name), NULL, 0)
                : zend_string_init(ZEND_STRL("cli.command"), 0));
    } else {
        ZVAL_STR(prop_type, zend_string_init(ZEND_STRL("web"), 0));
        ZVAL_STR(prop_name, zend_string_init(ZEND_STRL("web.request"), 0));
    }
    zval *prop_service = ddtrace_spandata_property_service(span);
    ZVAL_STR_COPY(prop_service, ZSTR_LEN(get_DD_SERVICE()) ? get_DD_SERVICE() : Z_STR_P(prop_name));

    if (Z_TYPE(PG(http_globals)[TRACK_VARS_SERVER]) == IS_ARRAY || zend_is_auto_global_str(ZEND_STRL("_SERVER"))) {
        zend_string *headername;
        zval *headerval;
        ZEND_HASH_FOREACH_STR_KEY_VAL_IND(Z_ARR(PG(http_globals)[TRACK_VARS_SERVER]), headername, headerval) {
            ZVAL_DEREF(headerval);
            if (Z_TYPE_P(headerval) == IS_STRING && headername && ZSTR_LEN(headername) > 5 &&
                memcmp(ZSTR_VAL(headername), "HTTP_", 5) == 0) {
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

    ddtrace_integration *web_integration = &ddtrace_integrations[DDTRACE_INTEGRATION_WEB];
    if (get_DD_TRACE_ANALYTICS_ENABLED() || web_integration->is_analytics_enabled()) {
        zend_array *metrics = ddtrace_spandata_property_metrics(span);
        zval sample_rate;
        ZVAL_DOUBLE(&sample_rate, web_integration->get_sample_rate());
        zend_hash_str_add_new(metrics, ZEND_STRL("_dd1.sr.eausr"), &sample_rate);
    }
}

static void _serialize_meta(zval *el, ddtrace_span_fci *span_fci) {
    ddtrace_span_t *span = &span_fci->span;
    bool top_level_span = span->parent_id == DDTRACE_G(distributed_parent_trace_id);
    zval meta_zv, *meta = ddtrace_spandata_property_meta_zval(span);

    array_init(&meta_zv);
    ZVAL_DEREF(meta);
    if (Z_TYPE_P(meta) == IS_ARRAY) {
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

    zval *exception_zv = ddtrace_spandata_property_exception(&span_fci->span);
    if (Z_TYPE_P(exception_zv) == IS_OBJECT && instanceof_function(Z_OBJCE_P(exception_zv), zend_ce_throwable)) {
        ddtrace_exception_to_meta(Z_OBJ_P(exception_zv), meta, dd_add_meta_array);
    }

    if (top_level_span) {
        if (SG(sapi_headers).http_response_code) {
            add_assoc_str(meta, "http.status_code", zend_long_to_str(SG(sapi_headers).http_response_code));
            if (SG(sapi_headers).http_response_code >= 500) {
                zval zv = {0}, *value;
                if ((value = zend_hash_str_add(Z_ARR_P(meta), ZEND_STRL("error.type"), &zv))) {
                    ZVAL_STR(value, zend_string_init(ZEND_STRL("Internal Server Error"), 0));
                }
            }
        }

        zend_llist_position pos;
        zend_llist *sapi_headers = &SG(sapi_headers).headers;
        for (sapi_header_struct *h = (sapi_header_struct *)zend_llist_get_first_ex(sapi_headers, &pos); h;
             h = (sapi_header_struct *)zend_llist_get_next_ex(sapi_headers, &pos)) {
            if (!h->header_len) {
            next_header:
                continue;
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
            dd_add_header_to_meta(Z_ARR_P(meta), "response", lowerheader, headerval);

            zend_string_release(headerval);
            zend_string_release(lowerheader);
        }
    }

    zend_bool error = ddtrace_hash_find_ptr(Z_ARR_P(meta), ZEND_STRL("error.msg")) ||
                      ddtrace_hash_find_ptr(Z_ARR_P(meta), ZEND_STRL("error.type"));
    if (error) {
        add_assoc_long(el, "error", 1);
    }

    if (zend_array_count(Z_ARRVAL_P(meta))) {
        add_assoc_zval(el, "meta", meta);
    } else {
        zval_ptr_dtor(meta);
    }
}

static bool dd_rule_matches(zval *pattern, zend_string* value) {
    if (Z_TYPE_P(pattern) != IS_STRING) {
        return false;
    }

    char *p = Z_STRVAL_P(pattern);
    char *s = ZSTR_VAL(value);

    int wildcards = 0;
    while (*p) {
        if (*(p++) == '*') {
            ++wildcards;
        }
    }
    p = Z_STRVAL_P(pattern);

    ALLOCA_FLAG(use_heap)
    char **backtrack_points = do_alloca(wildcards * 2 * sizeof(char *), use_heap);
    int backtrack_idx = 0;

    while (*p) {
        if (!*s) {
            while (*p == '*') {
                ++p;
            }
            free_alloca(backtrack_points, use_heap);
            return !*p;
        }
        if (*s == *p || *p == '.') {
            ++s, ++p;
        } else if (*p == '*') {
            backtrack_points[backtrack_idx++] = ++p;
            backtrack_points[backtrack_idx++] = s;
        } else {
            do {
                if (backtrack_idx > 0) {
                    backtrack_idx -= 2;
                    p = backtrack_points[backtrack_idx];
                    s = ++backtrack_points[backtrack_idx + 1];
                } else {
                    free_alloca(backtrack_points, use_heap);
                    return false;
                }
            } while (!*s);
            backtrack_idx += 2;
        }
    }

    free_alloca(backtrack_points, use_heap);

    return true;
}

static HashTable dd_span_sampling_limiters;
#if ZTS
static pthread_rwlock_t dd_span_sampling_limiter_lock;
#endif

struct dd_sampling_bucket {
    _Atomic int64_t hit_count;
    _Atomic uint64_t last_update;
};

void ddtrace_clear_span_sampling_limiter(zval *zv) {
    free(Z_PTR_P(zv));
}

void ddtrace_initialize_span_sampling_limiter(void) {
#if ZTS
    pthread_rwlock_init(&dd_span_sampling_limiter_lock, NULL);
#endif

    zend_hash_init(&dd_span_sampling_limiters, 8, obsolete, ddtrace_clear_span_sampling_limiter, 1);
}

void ddtrace_shutdown_span_sampling_limiter(void) {
#if ZTS
    pthread_rwlock_destroy(&dd_span_sampling_limiter_lock);
#endif

    zend_hash_destroy(&dd_span_sampling_limiters);
}

void ddtrace_serialize_span_to_array(ddtrace_span_fci *span_fci, zval *array) {
    ddtrace_span_t *span = &span_fci->span;
    bool top_level_span = span->parent_id == DDTRACE_G(distributed_parent_trace_id);
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

    // TODO: SpanData::$service defaults to parent SpanData::$service or DD_SERVICE if root span
    zval *prop_service = ddtrace_spandata_property_service(span);
    ZVAL_DEREF(prop_service);
    zval prop_service_as_string;
    if (Z_TYPE_P(prop_service) > IS_NULL) {
        ddtrace_convert_to_string(&prop_service_as_string, prop_service);

        zend_array *service_mappings = get_DD_SERVICE_MAPPING();
        zval *new_name = zend_hash_find(service_mappings, Z_STR(prop_service_as_string));
        if (new_name) {
            zend_string_release(Z_STR(prop_service_as_string));
            ZVAL_COPY(&prop_service_as_string, new_name);
        }

        add_assoc_zval(el, "service", &prop_service_as_string);
    }

    // SpanData::$type is optional and defaults to 'custom' at the Agent level
    zval *prop_type = ddtrace_spandata_property_type(span);
    ZVAL_DEREF(prop_type);
    zval prop_type_as_string;
    ZVAL_UNDEF(&prop_type_as_string);
    if (Z_TYPE_P(prop_type) > IS_NULL) {
        ddtrace_convert_to_string(&prop_type_as_string, prop_type);
        _add_assoc_zval_copy(el, "type", &prop_type_as_string);
    }

    // Notify profiling for Endpoint Profiling.
    if (profiling_notify_trace_finished && top_level_span && Z_TYPE(prop_resource_as_string) == IS_STRING) {
        zai_string_view type = Z_TYPE(prop_type_as_string) == IS_STRING
                               ? ZAI_STRING_FROM_ZSTR(Z_STR(prop_type_as_string))
                               : ZAI_STRL_VIEW("custom");
        zai_string_view resource = ZAI_STRING_FROM_ZSTR(Z_STR(prop_resource_as_string));
        ddtrace_log_debug("Notifying profiler of finished local root span.");
        profiling_notify_trace_finished(span->span_id, type, resource);
    }

    zval_ptr_dtor(&prop_type_as_string);
    zval_ptr_dtor(&prop_resource_as_string);

    if (ddtrace_fetch_prioritySampling_from_span(span->chunk_root) <= 0) {
        zval *rule;
        ZEND_HASH_FOREACH_VAL(get_DD_SPAN_SAMPLING_RULES(), rule) {
            if (Z_TYPE_P(rule) != IS_ARRAY) {
                continue;
            }

            bool rule_matches = true;

            zval *rule_service;
            if ((rule_service = zend_hash_str_find(Z_ARR_P(rule), ZEND_STRL("service")))) {
                if (Z_TYPE_P(prop_service) > IS_NULL) {
                    rule_matches &= dd_rule_matches(rule_service, Z_STR(prop_service_as_string));
                } else {
                    rule_matches &= false;
                }
            }
            zval *rule_name;
            if ((rule_name = zend_hash_str_find(Z_ARR_P(rule), ZEND_STRL("name")))) {
                if (Z_TYPE_P(prop_name) > IS_NULL) {
                    rule_matches &= dd_rule_matches(rule_name, Z_STR_P(prop_name));
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
                pthread_rwlock_rdlock(&dd_span_sampling_limiter_lock);
#endif
                struct dd_sampling_bucket *sampling_bucket = zend_hash_find_ptr(&dd_span_sampling_limiters, rule_key);
#if ZTS
                pthread_rwlock_unlock(&dd_span_sampling_limiter_lock);
#endif

                struct timespec timespec;
                clock_gettime(CLOCK_MONOTONIC, &timespec);
                uint64_t timeval = timespec.tv_sec * 1000000000 + timespec.tv_nsec;

                if (!sampling_bucket) {
                    struct dd_sampling_bucket *new_sampling_bucket = malloc(sizeof(*new_sampling_bucket));
                    new_sampling_bucket->hit_count = 1;
                    new_sampling_bucket->last_update = timeval;

#if ZTS
                    pthread_rwlock_wrlock(&dd_span_sampling_limiter_lock);
#endif
                    if (!zend_hash_add_ptr(&dd_span_sampling_limiters, rule_key, new_sampling_bucket)) {
                        free(new_sampling_bucket);
                        sampling_bucket = zend_hash_find_ptr(&dd_span_sampling_limiters, rule_key);
                    }
#if ZTS
                    pthread_rwlock_unlock(&dd_span_sampling_limiter_lock);
#endif
                }

                zend_string_release(rule_key);

                if (sampling_bucket) {
                    const int nanosecond = 1000000000;

                    // restore allowed time basis
                    uint64_t old_time = atomic_exchange(&sampling_bucket->last_update, timeval);
                    int64_t clear_counter = (int64_t)((long double)(timeval - old_time) * max_per_second);

                    int64_t previous_hits = atomic_fetch_sub(&sampling_bucket->hit_count, clear_counter);
                    if (previous_hits < clear_counter) {
                        atomic_fetch_add(&sampling_bucket->hit_count, previous_hits > 0 ? clear_counter - previous_hits : clear_counter);
                    }

                    previous_hits = atomic_fetch_add(&sampling_bucket->hit_count, nanosecond);
                    if ((long double)previous_hits / nanosecond >= max_per_second) {
                        atomic_fetch_sub(&sampling_bucket->hit_count, nanosecond);
                        break; // limit exceeded
                    }
                }
            }

            zend_array *metrics = ddtrace_spandata_property_metrics(span);

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

    _serialize_meta(el, span_fci);

    zval *metrics = ddtrace_spandata_property_metrics_zval(span);
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

    if (top_level_span && get_DD_TRACE_MEASURE_COMPILE_TIME()) {
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

void ddtrace_save_active_error_to_metadata(void) {
    if (!DDTRACE_G(active_error).type) {
        return;
    }

    dd_error_info error = {
        .type = dd_error_type(DDTRACE_G(active_error).type),
        .msg = zend_string_copy(DDTRACE_G(active_error).message),
        .stack = dd_fatal_error_stack(),
    };
    for (ddtrace_span_fci *span = DDTRACE_G(open_spans_top); span; span = span->next) {
        if (Z_TYPE_P(ddtrace_spandata_property_exception(&span->span)) == IS_OBJECT) {  // exceptions take priority
            continue;
        }

        dd_fatal_error_to_meta(ddtrace_spandata_property_meta(&span->span), error);
    }
    zend_string_release(error.type);
    zend_string_release(error.msg);
    if (error.stack) {
        zend_string_release(error.stack);
    }
}

void ddtrace_error_cb(DDTRACE_ERROR_CB_PARAMETERS) {
    UNUSED(error_filename, error_lineno);

    /* We need the error handling to place nicely with the sandbox. The best
     * idea so far is to execute fatal error handling code iff the error handling
     * mode is set to EH_NORMAL. If it's something else, such as EH_SUPPRESS or
     * EH_THROW, then they are likely to be handled and accordingly they
     * shouldn't be treated as fatal.
     */
    bool is_fatal_error = orig_type & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR);
    if (EXPECTED(EG(active)) && EG(error_handling) == EH_NORMAL && UNEXPECTED(is_fatal_error)) {
        /* If there is a fatal error in shutdown then this might not be an array
         * because we set it to IS_NULL in RSHUTDOWN. We probably want a more
         * robust way of detecting this, but I'm not sure how yet.
         */
        if (Z_TYPE(DDTRACE_G(additional_trace_meta)) == IS_ARRAY) {
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
            dd_fatal_error_to_meta(Z_ARR(DDTRACE_G(additional_trace_meta)), error);
            ddtrace_span_fci *span;
            for (span = DDTRACE_G(open_spans_top); span; span = span->next) {
                if (Z_TYPE_P(ddtrace_spandata_property_exception(&span->span)) > IS_FALSE) {
                    continue;
                }

                dd_fatal_error_to_meta(ddtrace_spandata_property_meta(&span->span), error);
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
