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

#include <ext/standard/php_smart_str.h>
#include <ext/standard/php_string.h>
// comment to prevent clang from reordering these headers
#include <SAPI.h>

#include "compat_string.h"
#include "ddtrace.h"
#include "engine_hooks.h"
#include "logging.h"
#include "mpack/mpack.h"
#include "span.h"
#include "uri_normalization.h"

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
    int key_type;
    bool is_assoc = 0;

    for (zend_hash_internal_pointer_reset_ex(ht, &iterator);
         (key_type = zend_hash_get_current_key_ex(ht, &string_key, &str_len, &num_key, 0, &iterator)) !=
         HASH_KEY_NON_EXISTANT;
         zend_hash_move_forward_ex(ht, &iterator)) {
        is_assoc = is_assoc || key_type == HASH_KEY_IS_STRING;
    }

    if (is_assoc) {
        mpack_start_map(writer, zend_hash_num_elements(ht));
    } else {
        mpack_start_array(writer, zend_hash_num_elements(ht));
    }

    for (zend_hash_internal_pointer_reset_ex(ht, &iterator);
         zend_hash_get_current_data_ex(ht, (void **)&tmp, &iterator) == SUCCESS;
         zend_hash_move_forward_ex(ht, &iterator)) {
        key_type = zend_hash_get_current_key_ex(ht, &string_key, &str_len, &num_key, 0, &iterator);
        // Writing the key, if associative
        bool zval_string_as_uint64 = false;
        if (is_assoc == 1) {
            char num_str_buf[MAX_ID_BUFSIZ];
            if (key_type != HASH_KEY_IS_STRING) {
                string_key = num_str_buf;
                sprintf(num_str_buf, "%ld", num_key);
            }
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
    }

    if (is_assoc) {
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

typedef int (*add_tag_fn_t)(void *context, zai_string_view key, zai_string_view value);

static int dd_exception_to_error_msg(zval *exception, void *context, add_tag_fn_t add_tag TSRMLS_DC) {
    zai_string_view msg = zai_exception_message(exception TSRMLS_CC);
    zval *line = ZAI_EXCEPTION_PROPERTY(exception, "line");
    zval *file = ZAI_EXCEPTION_PROPERTY(exception, "file");

    char *error_text, *status_line;
    zend_bool caught = SG(sapi_headers).http_response_code >= 500;

    if (caught) {
        if (SG(sapi_headers).http_status_line) {
            asprintf(&status_line, " (%s)", SG(sapi_headers).http_status_line);
        } else {
            asprintf(&status_line, " (%d)", SG(sapi_headers).http_response_code);
        }
    }

    int error_len = asprintf(&error_text, "%s %s%s%s%.*s in %s:%ld", caught ? "Caught" : "Uncaught",
                             Z_OBJCE_P(exception)->name, caught ? status_line : "", msg.len > 0 ? ": " : "",
                             (int)msg.len, msg.ptr, Z_TYPE_P(file) == IS_STRING ? Z_STRVAL_P(file) : "Unknown",
                             Z_TYPE_P(line) == IS_LONG ? Z_LVAL_P(line) : 0);

    if (caught) {
        free(status_line);
    }

    zai_string_view key = ZAI_STRL_VIEW("error.msg");
    zai_string_view value = {.ptr = error_text, .len = error_len};
    int result = add_tag(context, key, value);

    free(error_text);
    return result;
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

static int dd_exception_to_error_stack(char *trace, size_t trace_len, void *context, add_tag_fn_t add_tag) {
    zai_string_view key = ZAI_STRL_VIEW("error.stack");
    zai_string_view value = {.ptr = trace, .len = trace_len};
    return add_tag(context, key, value);
}

/* "int" return types are SUCCESS=0, anything else is a failure
 * Guarantees that add_tag will only be called once per tag, will stop trying to add tags if one fails.
 */
static int ddtrace_exception_to_meta(zval *exception, void *context, add_tag_fn_t add_meta TSRMLS_DC) {
    zval *exception_root = exception;
    smart_str trace_str = zai_get_trace_without_args_from_exception(exception TSRMLS_CC);
    char *full_trace = trace_str.c;
    size_t full_trace_len = trace_str.len;

    zval *previous = ZAI_EXCEPTION_PROPERTY(exception, "previous");
    while (Z_TYPE_P(previous) == IS_OBJECT && !Z_OBJPROP_P(previous)->nApplyCount &&
           instanceof_function(Z_OBJCE_P(previous), zend_exception_get_default(TSRMLS_C) TSRMLS_CC)) {
        smart_str trace_string = zai_get_trace_without_args_from_exception(previous TSRMLS_CC);

        zai_string_view msg = zai_exception_message(exception TSRMLS_CC);
        zval *line = ZAI_EXCEPTION_PROPERTY(exception, "line");
        zval *file = ZAI_EXCEPTION_PROPERTY(exception, "file");

        char *old_trace = full_trace;
        full_trace_len =
            spprintf(&full_trace, 0, "%.*s\n\nNext %s%s%s in %s:%ld\nStack trace:\n%.*s", (int)trace_string.len,
                     trace_string.c, Z_OBJCE_P(exception)->name, msg.len ? ": " : "", msg.ptr,
                     Z_TYPE_P(file) == IS_STRING ? Z_STRVAL_P(file) : "Unknown",
                     Z_TYPE_P(line) == IS_LONG ? Z_LVAL_P(line) : 0, (int)full_trace_len, old_trace);
        efree(old_trace);
        smart_str_free(&trace_string);

        ++Z_OBJPROP_P(previous)->nApplyCount;
        exception = previous;
        previous = ZAI_EXCEPTION_PROPERTY(exception, "previous");
    }

    previous = ZAI_EXCEPTION_PROPERTY(exception_root, "previous");
    while (Z_TYPE_P(previous) == IS_OBJECT && !Z_OBJPROP_P(previous)->nApplyCount) {
        --Z_OBJPROP_P(previous)->nApplyCount;
        previous = ZAI_EXCEPTION_PROPERTY(previous, "previous");
    }

    bool success = dd_exception_to_error_msg(exception, context, add_meta TSRMLS_CC) == SUCCESS &&
                   dd_exception_to_error_type(exception, context, add_meta TSRMLS_CC) == SUCCESS &&
                   dd_exception_to_error_stack(full_trace, full_trace_len, context, add_meta) == SUCCESS;

    efree(full_trace);

    return success ? SUCCESS : FAILURE;
}

typedef struct dd_error_info {
    zval *type;
    zval *msg;
    zval *stack;
} dd_error_info;

static zval *dd_error_type(int code) {
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

static zval *dd_fatal_error_stack(void) {
    zval *stack = NULL;
    zval trace = zval_used_for_init;
    TSRMLS_FETCH();

    zend_fetch_debug_backtrace(&trace, 0, DEBUG_BACKTRACE_IGNORE_ARGS, 0 TSRMLS_CC);
    if (Z_TYPE(trace) == IS_ARRAY) {
        smart_str stack_str = zai_get_trace_without_args(Z_ARRVAL(trace));
        if (stack_str.c) {
            MAKE_STD_ZVAL(stack);
            ZVAL_STRINGL(stack, stack_str.c, stack_str.len, 0);
        }
    }
    zval_dtor(&trace);
    return stack;
}

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
    add_assoc_stringl_ex((zval *)meta, key.ptr, key.len + 1, (char *)value.ptr, value.len, 1);
    return SUCCESS;
}

static void dd_add_header_to_meta(HashTable *meta, const char *type, char *lowerheader, size_t headerlen,
                                  zval *headerval) {
    if (zend_hash_exists(get_DD_TRACE_HEADER_TAGS(), lowerheader, headerlen + 1)) {
        for (char *ptr = lowerheader; *ptr; ++ptr) {
            if ((*ptr < 'a' || *ptr > 'z') && *ptr != '-' && (*ptr < '0' || *ptr > '9')) {
                *ptr = '_';
            }
        }

        char *headertag;
        headerlen = spprintf(&headertag, 0, "http.%s.headers.%s", type, lowerheader);
        zval_addref_p(headerval);
        zend_hash_update(meta, headertag, headerlen + 1, (void **)&headerval, sizeof(zval **), NULL);
        efree(headertag);
    }
}

void ddtrace_set_global_span_properties(ddtrace_span_t *span TSRMLS_DC) {
    zval *meta = ddtrace_spandata_property_meta(span);

    zai_string_view version = get_DD_VERSION();
    if (version.len > 0) {  // non-empty
        add_assoc_stringl(meta, "version", (char *)version.ptr, version.len, 1);
    }

    zai_string_view env = get_DD_ENV();
    if (env.len > 0) {  // non-empty
        add_assoc_stringl(meta, "env", (char *)env.ptr, env.len, 1);
    }

    if (DDTRACE_G(dd_origin)) {
        add_assoc_string(meta, "_dd.origin", DDTRACE_G(dd_origin), 1);
    }

    HashTable *global_tags = get_DD_TAGS();
    char *key;
    uint key_len;
    ulong num_key;
    zval **val;
    HashPosition pos;

    for (zend_hash_internal_pointer_reset_ex(global_tags, &pos);
         zend_hash_get_current_key_ex(global_tags, &key, &key_len, &num_key, 0, &pos),
         zend_hash_get_current_data_ex(global_tags, (void **)&val, &pos) == SUCCESS;
         zend_hash_move_forward_ex(global_tags, &pos)) {
        if (zend_hash_add(Z_ARRVAL_P(meta), key, key_len, (void **)val, sizeof(zval *), NULL) == SUCCESS) {
            zval_addref_p(*val);
        }
    }

    for (zend_hash_internal_pointer_reset_ex(&DDTRACE_G(additional_global_tags), &pos);
         zend_hash_get_current_key_ex(&DDTRACE_G(additional_global_tags), &key, &key_len, &num_key, 0, &pos),
         zend_hash_get_current_data_ex(&DDTRACE_G(additional_global_tags), (void **)&val, &pos) == SUCCESS;
         zend_hash_move_forward_ex(&DDTRACE_G(additional_global_tags), &pos)) {
        if (zend_hash_add(Z_ARRVAL_P(meta), key, key_len, (void **)val, sizeof(zval *), NULL) == SUCCESS) {
            zval_addref_p(*val);
        }
    }

    zval **id = ddtrace_spandata_property_id_write(span);
    MAKE_STD_ZVAL(*id);
    char span_id_str[MAX_ID_BUFSIZ];
    sprintf(span_id_str, "%" PRIu64, span->span_id);
    ZVAL_STRING(*id, span_id_str, 1);
}

static const char *dd_get_req_uri(TSRMLS_D) {
    const char *uri = NULL;
    zval *_server = PG(http_globals)[TRACK_VARS_SERVER];
    if (Z_TYPE_P(_server) == IS_ARRAY || zend_is_auto_global(ZEND_STRL("_SERVER") TSRMLS_CC)) {
        zval **req_uri;
        if (zend_hash_find(Z_ARRVAL_P(_server), ZEND_STRS("REQUEST_URI"), (void **)&req_uri) == SUCCESS) {
            if (Z_TYPE_PP(req_uri) == IS_STRING) {
                uri = Z_STRVAL_PP(req_uri);
            }
        }
    }

    if (!uri) {
        uri = SG(request_info).request_uri;
    }

    return uri;
}

static smart_str dd_build_req_url(TSRMLS_D) {
    smart_str url_str = {0};

    zval *_server = PG(http_globals)[TRACK_VARS_SERVER];
    const char *uri = dd_get_req_uri(TSRMLS_C);
    if (!uri) {
        return url_str;
    }

    int uri_len;
    char *question_mark = strchr(uri, '?');
    if (question_mark) {
        uri_len = question_mark - uri;
    } else {
        uri_len = strlen(uri);
    }

    zend_bool is_https = zend_hash_exists(Z_ARRVAL_P(_server), ZEND_STRS("HTTPS"));

    zval **host_zv;
    if (Z_TYPE_P(_server) != IS_ARRAY ||
        (zend_hash_find(Z_ARRVAL_P(_server), ZEND_STRS("HTTP_HOST"), (void **)&host_zv) == FAILURE &&
         zend_hash_find(Z_ARRVAL_P(_server), ZEND_STRS("SERVER_NAME"), (void **)&host_zv) == FAILURE) ||
        Z_TYPE_PP(host_zv) != IS_STRING) {
        return url_str;
    }

    smart_str_appendl(&url_str, "http", sizeof("http") - 1);
    if (is_https) {
        smart_str_appendc(&url_str, 's');
    }
    smart_str_appendl(&url_str, "://", sizeof("://") - 1);
    smart_str_appendl(&url_str, Z_STRVAL_PP(host_zv), Z_STRLEN_PP(host_zv));
    smart_str_appendl(&url_str, uri, uri_len);
    smart_str_0(&url_str);

    return url_str;
}

void ddtrace_set_root_span_properties(ddtrace_span_t *span TSRMLS_DC) {
    zval *meta = ddtrace_spandata_property_meta(span);

    zend_hash_copy(Z_ARRVAL_P(meta), &DDTRACE_G(root_span_tags_preset), (copy_ctor_func_t)zval_add_ref, NULL,
                   sizeof(zval *));

    add_assoc_long(meta, "system.pid", (long)getpid());

    const char *method = SG(request_info).request_method;
    if (get_DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED() && method) {
        add_assoc_string(meta, "http.method", (char *)method, 1);

        smart_str http_url = dd_build_req_url(TSRMLS_C);
        if (http_url.c) {
            add_assoc_string(meta, "http.url", http_url.c, 0);
        }

        const char *uri = dd_get_req_uri(TSRMLS_C);
        zval **prop_resource = ddtrace_spandata_property_resource_write(span);
        MAKE_STD_ZVAL(*prop_resource);
        if (uri) {
            zai_string_view normalized =
                ddtrace_uri_normalize_incoming_path((zai_string_view){.ptr = uri, .len = strlen(uri)});
            char *resource;
            spprintf(&resource, 0, "%s %s", method, normalized.ptr);
            efree((char *)normalized.ptr);
            ZVAL_STRING(*prop_resource, resource, 0);
        } else {
            ZVAL_STRING(*prop_resource, method, 1);
        }
    }
    zval **prop_type = ddtrace_spandata_property_type_write(span);
    MAKE_STD_ZVAL(*prop_type);

    zval **prop_name = ddtrace_spandata_property_name_write(span);
    MAKE_STD_ZVAL(*prop_name);

    if (strcmp(sapi_module.name, "cli") == 0) {
        ZVAL_STRING(*prop_type, "cli", 1);
        if (SG(request_info).argc > 0) {
            char *basepath;
            size_t pathlen;
            php_basename(SG(request_info).argv[0], strlen(SG(request_info).argv[0]), NULL, 0, &basepath,
                         &pathlen TSRMLS_CC);
            ZVAL_STRINGL(*prop_name, basepath, pathlen, 0);
        } else {
            ZVAL_STRING(*prop_name, "cli.command", 1);
        }
    } else {
        ZVAL_STRING(*prop_type, "web", 1);
        ZVAL_STRING(*prop_name, "web.request", 1);
    }

    zval **prop_service = ddtrace_spandata_property_service_write(span);
    MAKE_STD_ZVAL(*prop_service);
    ZVAL_STRING(*prop_service, get_DD_SERVICE().len ? get_DD_SERVICE().ptr : Z_STRVAL_PP(prop_name), 1);

    if ((PG(http_globals)[TRACK_VARS_SERVER] && Z_TYPE_P(PG(http_globals)[TRACK_VARS_SERVER]) == IS_ARRAY) ||
        zend_is_auto_global(ZEND_STRL("_SERVER") TSRMLS_CC)) {
        HashPosition pos;
        char *headername;
        uint headername_len;
        ulong num_key;
        HashTable *server = Z_ARRVAL_P(PG(http_globals)[TRACK_VARS_SERVER]);
        zval **headerval;
        for (zend_hash_internal_pointer_reset_ex(server, &pos);
             zend_hash_get_current_data_ex(server, (void **)&headerval, &pos) == SUCCESS;
             zend_hash_move_forward_ex(server, &pos)) {
            int key_type;
            key_type = zend_hash_get_current_key_ex(server, &headername, &headername_len, &num_key, 0, &pos);
            --headername_len;  // subtract trailing zero-byte from length
            if (key_type == HASH_KEY_IS_STRING && Z_TYPE_PP(headerval) == IS_STRING && headername_len > 5 &&
                memcmp(headername, "HTTP_", 5) == 0) {
                char *lowerheader = estrndup(headername + 5, headername_len - 5);
                for (char *ptr = lowerheader; *ptr; ++ptr) {
                    if (*ptr >= 'A' && *ptr <= 'Z') {
                        *ptr -= 'A' - 'a';
                    } else if (*ptr == '_') {
                        *ptr = '-';
                    }
                }

                dd_add_header_to_meta(Z_ARRVAL_P(meta), "request", lowerheader, headername_len - 5, *headerval);
                efree(lowerheader);
            }
        }
    }

    if (get_DD_TRACE_REPORT_HOSTNAME()) {
#ifndef HOST_NAME_MAX
#define HOST_NAME_MAX 255
#endif

        char *hostname = emalloc(HOST_NAME_MAX + 1);
        if (gethostname(hostname, HOST_NAME_MAX + 1)) {
            efree(hostname);
        } else {
            hostname = erealloc(hostname, strlen(hostname) + 1);
            add_assoc_string(meta, "_dd.hostname", hostname, 0);
        }
    }

    ddtrace_integration *web_integration = &ddtrace_integrations[DDTRACE_INTEGRATION_WEB];
    if (get_DD_TRACE_ANALYTICS_ENABLED() || web_integration->is_analytics_enabled()) {
        zval *metrics = ddtrace_spandata_property_metrics(span);
        add_assoc_double(metrics, "_dd1.sr.eausr", web_integration->get_sample_rate());
    }
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

    zval *exception_zv = ddtrace_spandata_property_exception(&span_fci->span);
    if (exception_zv && Z_TYPE_P(exception_zv) == IS_OBJECT &&
        instanceof_function(Z_OBJCE_P(exception_zv), zend_exception_get_default(TSRMLS_C) TSRMLS_CC)) {
        ddtrace_exception_to_meta(exception_zv, meta, dd_add_meta_array TSRMLS_CC);
    }

    if (top_level_span) {
        if (SG(sapi_headers).http_response_code) {
            zval *status_code;
            MAKE_STD_ZVAL(status_code);
            ZVAL_LONG(status_code, SG(sapi_headers).http_response_code);
            convert_to_string(status_code);
            add_assoc_zval(meta, "http.status_code", status_code);
            if (SG(sapi_headers).http_response_code >= 500) {
                zval **zv = NULL;
                if (zend_hash_add(Z_ARRVAL_P(meta), "error.type", sizeof("error.type"), (void **)&zv, sizeof(zval **),
                                  (void **)&zv) == SUCCESS) {
                    MAKE_STD_ZVAL(*zv);
                    ZVAL_STRING(*zv, "Internal Server Error", 1);
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
            char *lowerheader = emalloc(h->header_len);
            char *lowerptr = lowerheader, *header = h->header, *end = header + h->header_len;
            for (; *header != ':'; ++header, ++lowerptr) {
                if (header >= end) {
                    efree(lowerheader);
                    goto next_header;
                }
                *lowerptr = (char)(*header >= 'A' && *header <= 'Z' ? *header - ('A' - 'a') : *header);
            }
            // not actually RFC 7230 compliant (not allowing whitespace there), but most clients accept it. Handle it.
            while (lowerptr > lowerheader && isspace(lowerptr[-1])) {
                --lowerptr;
            }
            *lowerptr = 0;
            size_t headerlen = lowerptr - lowerheader;
            lowerheader = erealloc(lowerheader, headerlen + 1);
            if (header + 1 < end) {
                ++header;
            }

            while (header < end && isspace(*header)) {
                ++header;
            }
            while (end > header && isspace(end[-1])) {
                --end;
            }

            zval *headerval;
            MAKE_STD_ZVAL(headerval);
            ZVAL_STRINGL(headerval, header, end - header, 1);
            dd_add_header_to_meta(Z_ARRVAL_P(meta), "response", lowerheader, headerlen, headerval);

            zval_ptr_dtor(&headerval);
            efree(lowerheader);
        }
    }

    zend_bool error = zend_hash_exists(Z_ARRVAL_P(meta), "error.msg", sizeof("error.msg")) ||
                      zend_hash_exists(Z_ARRVAL_P(meta), "error.type", sizeof("error.type"));
    if (error) {
        add_assoc_long(el, "error", 1);
    }

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
        if (zend_hash_find(service_mappings, Z_STRVAL_P(prop_service_as_string), Z_STRLEN_P(prop_service_as_string) + 1,
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

void ddtrace_save_active_error_to_metadata(TSRMLS_D) {
    if (!DDTRACE_G(active_error).type) {
        return;
    }

    dd_error_info error = {
        .type = dd_error_type(DDTRACE_G(active_error).type),
        .msg = DDTRACE_G(active_error).message,
        .stack = dd_fatal_error_stack(),
    };

    for (ddtrace_span_fci *span = DDTRACE_G(open_spans_top); span; span = span->next) {
        zval *exception = ddtrace_spandata_property_exception(&span->span);
        if (exception && Z_TYPE_P(exception) == IS_OBJECT) {  // exceptions take priority
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
            dd_error_info error = {
                .type = dd_error_type(type),
                .msg = dd_fatal_error_msg(format, args),
                .stack = dd_fatal_error_stack(),
            };
            dd_fatal_error_to_meta(&DDTRACE_G(additional_trace_meta), error);

            ddtrace_span_fci *span;
            for (span = DDTRACE_G(open_spans_top); span; span = span->next) {
                zval *prop_exception = ddtrace_spandata_property_exception(&span->span);
                if (prop_exception != NULL && Z_TYPE_P(prop_exception) != IS_NULL &&
                    (Z_TYPE_P(prop_exception) != IS_BOOL || Z_BVAL_P(prop_exception) != 0)) {
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
