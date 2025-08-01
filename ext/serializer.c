#include <Zend/zend.h>
#include <Zend/zend_builtin_functions.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_interfaces.h>
#include <Zend/zend_smart_str.h>
#include <Zend/zend_types.h>
#include <Zend/zend_string.h>
#include <inttypes.h>
#include <php.h>
#include <stdbool.h>
#include <stdlib.h>
#include <string.h>

#include <ext/standard/php_string.h>
#include "zend_hash.h"
#include "zend_portability.h"
#include "zend_variables.h"
#include <components-rs/ddtrace.h>
#include <components-rs/sidecar.h>
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
#include <zend_abstract_interface/symbols/symbols.h>
#include <ext/standard/url.h>

#include "compat_string.h"
#include "ddtrace.h"
#include "engine_api.h"
#include "engine_hooks.h"
#include "git.h"
#include "ip_extraction.h"
#include <components/log/log.h>
#include "priority_sampling/priority_sampling.h"
#include "span.h"
#include "uri_normalization.h"
#include "user_request.h"
#include "ddshared.h"
#include "zend_hrtime.h"
#include "trace_source.h"
#include "exception_serialize.h"
#include "sidecar.h"

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

static void dd_add_header_to_meta(zend_array *meta, const char *type, zend_string *lowerheader,
                                  zend_string *headerval) {
    zval *header_config = zend_hash_find(get_DD_TRACE_HEADER_TAGS(), lowerheader);
    if (header_config != NULL && Z_TYPE_P(header_config) == IS_STRING) {
        zend_string *header_config_str = Z_STR_P(header_config);
        zend_string *headertag;
        if (ZSTR_LEN(header_config_str) == 0) {
            for (char *ptr = ZSTR_VAL(lowerheader); *ptr; ++ptr) {
                if ((*ptr < 'a' || *ptr > 'z') && *ptr != '-' && (*ptr < '0' || *ptr > '9')) {
                    *ptr = '_';
                }
            }
            headertag = zend_strpprintf(0, "http.%s.headers.%s", type, ZSTR_VAL(lowerheader));
        } else {
            headertag = zend_string_copy(header_config_str);
        }
        zval headerzv;
        ZVAL_STR_COPY(&headerzv, headerval);
        zend_hash_update(meta, headertag, &headerzv);
        zend_string_release(headertag);
    }
}

static void dd_add_header_to_rust_span(ddog_SpanBytes *span, const char *type, zend_string *lowerheader,
                                       zend_string *headerval) {
    zval *header_config = zend_hash_find(get_DD_TRACE_HEADER_TAGS(), lowerheader);
    if (header_config != NULL && Z_TYPE_P(header_config) == IS_STRING) {
        zend_string *header_config_str = Z_STR_P(header_config);
        zend_string *headertag;
        if (ZSTR_LEN(header_config_str) == 0) {
            for (char *ptr = ZSTR_VAL(lowerheader); *ptr; ++ptr) {
                if ((*ptr < 'a' || *ptr > 'z') && *ptr != '-' && (*ptr < '0' || *ptr > '9')) {
                    *ptr = '_';
                }
            }
            headertag = zend_strpprintf(0, "http.%s.headers.%s", type, ZSTR_VAL(lowerheader));
        } else {
            headertag = zend_string_copy(header_config_str);
        }

        ddog_add_span_meta_zstr(span, headertag, headerval);
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
    zval *prop_env = &span->property_env;
    zval *prop_version = &span->property_version;

    ZEND_HASH_FOREACH_STR_KEY_VAL(global_tags, global_key, global_val) {
        if ((zend_string_equals_literal(global_key, "env") && Z_TYPE_P(prop_env) == IS_STRING && Z_STRLEN_P(prop_env) > 0) ||
            (zend_string_equals_literal(global_key, "version") && Z_TYPE_P(prop_version) == IS_STRING && Z_STRLEN_P(prop_version) > 0) ||
            zend_string_equals_literal(global_key, "service")) {
            continue;
        }

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

static zend_string *dd_get_referrer_host(zend_array *_server) {
    if (_server) {
        zval *referer = zend_hash_str_find(_server, ZEND_STRL("HTTP_REFERER"));
        if (referer && Z_TYPE_P(referer) == IS_STRING) {
            php_url *url = php_url_parse(Z_STRVAL_P(referer));
            if (url && url->host) {
#if PHP_VERSION_ID >= 70300
                zend_string *host_str = zend_string_init(ZSTR_VAL(url->host), ZSTR_LEN(url->host), 0);
#else
                zend_string *host_str = zend_string_init(url->host, strlen(url->host), 0);
#endif
                php_url_free(url);
                return host_str;
            }
            if (url) {
                php_url_free(url);
            }
        }
    }
    return ZSTR_EMPTY_ALLOC();
}

static bool dd_set_mapped_peer_service(ddog_SpanBytes *span, zend_string *peer_service) {
    zend_array *peer_service_mapping = get_DD_TRACE_PEER_SERVICE_MAPPING();
    if (zend_hash_num_elements(peer_service_mapping) == 0 || !peer_service) {
        return false;
    }

    zval* mapped_service_zv = zend_hash_find(peer_service_mapping, peer_service);
    if (mapped_service_zv) {
        zend_string *mapped_service = zval_get_string(mapped_service_zv);
        ddog_add_str_span_meta_zstr(span, "peer.service.remapped_from", peer_service);
        ddog_add_str_span_meta_zstr(span, "peer.service", mapped_service);
        zend_string_release(mapped_service);
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

    zend_string *referrer_host = dd_get_referrer_host(data->server);
    if (referrer_host && ZSTR_LEN(referrer_host) > 0) {
        zval http_referrer_host;
        ZVAL_STR(&http_referrer_host, referrer_host);
        zend_hash_str_add_new(meta, ZEND_STRL("http.referrer_hostname"), &http_referrer_host);
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
    ZVAL_COPY_DEREF(prop_service, &parent->property_service);
    zval *prop_type = &span->property_type;
    zval_ptr_dtor(prop_type);
    ZVAL_COPY_DEREF(prop_type, &parent->property_type);

    zval *prop_baggage = &span->property_baggage, *prop_parent_baggage = &parent->property_baggage;
    zval_ptr_dtor(prop_baggage);
    ZVAL_COPY_DEREF(prop_baggage, prop_parent_baggage);

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
    ZVAL_COPY_DEREF(prop_version, version);

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
    ZVAL_COPY_DEREF(prop_env, env);
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

zend_string *ddtrace_active_service_name(void) {
    ddtrace_span_data *span = ddtrace_active_span();
    if (span) {
        return ddtrace_convert_to_str(&span->property_service);
    }
    zend_string *ini_service = get_DD_SERVICE();
    if (ZSTR_LEN(ini_service)) {
        return zend_string_copy(ini_service);
    }
    return ddtrace_default_service_name();
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
        ZVAL_COPY_DEREF(&span->property_origin, &parent_root->property_origin);
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
        SEPARATE_ARRAY(&span->property_baggage);
        zend_hash_copy(Z_ARR(span->property_baggage), &DDTRACE_G(baggage), zval_add_ref);

        if (DDTRACE_G(propagated_priority_sampling) != DDTRACE_PRIORITY_SAMPLING_UNSET) {
            ZVAL_LONG(&span->property_propagated_sampling_priority, DDTRACE_G(propagated_priority_sampling));
        }
        if (DDTRACE_G(default_priority_sampling) != DDTRACE_PRIORITY_SAMPLING_UNKNOWN) {
            ddtrace_set_priority_sampling_on_span(span, DDTRACE_G(default_priority_sampling), DD_MECHANISM_MANUAL);
        }

        if (DDTRACE_G(asm_event_emitted)) {
            span->asm_event_emitted = DDTRACE_G(asm_event_emitted);
            DDTRACE_G(asm_event_emitted) = false; // we attach this to the first root span after the asm event was detected (if there was none while emitted)
        }

        ddtrace_integration *web_integration = &ddtrace_integrations[DDTRACE_INTEGRATION_WEB];
        if (get_DD_TRACE_ANALYTICS_ENABLED() || web_integration->is_analytics_enabled()) {
            zval sample_rate;
            ZVAL_DOUBLE(&sample_rate, web_integration->get_sample_rate());
            zend_hash_str_add_new(metrics, ZEND_STRL("_dd1.sr.eausr"), &sample_rate);
        }

        if (get_DD_TRACE_GIT_METADATA_ENABLED()) {
            ddtrace_inject_git_metadata(&span->property_git_metadata);
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

static void dd_serialize_array_recursively(ddog_SpanBytes *target, zend_string *str, zval *value, bool convert_to_double) {
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
        } else if (convert_to_double) {
            ddog_add_span_metrics_zstr(target, str, 0.0);
        } else {
            ddog_add_zstr_span_meta_str(target, str, "");
        }

#if PHP_VERSION_ID >= 70400
        if (Z_TYPE_P(value) == IS_OBJECT) {
            zend_release_properties(arr);
        }
#endif
    } else if (convert_to_double) {
        ddog_add_span_metrics_zstr(target, str, zval_get_double(value));
    } else {
        zval val_as_string;
        ddtrace_convert_to_string(&val_as_string, value);
        ddog_add_span_meta_zstr(target, str, Z_STR_P(&val_as_string));
        zval_ptr_dtor(&val_as_string);
    }
}

static void dd_serialize_array_meta_recursively(ddog_SpanBytes *target, zend_string *str, zval *value) {
    dd_serialize_array_recursively(target, str, value, false);
}

static void dd_serialize_array_metrics_recursively(ddog_SpanBytes *target, zend_string *str, zval *value) {
    dd_serialize_array_recursively(target, str, value, true);
}

static void dd_serialize_array_meta_struct_recursively(ddog_SpanBytes *target, zend_string *str, zval *value) {
    char *data;
    size_t size;

    mpack_writer_t writer;
    mpack_writer_init_growable(&writer, &data, &size);
    int result = msgpack_write_zval(&writer, value, 5);
    mpack_writer_destroy(&writer);

    if (size == 0 || result == 0) {
        free(data);
        return;
    }

    ddog_add_zstr_span_meta_struct_CharSlice(target, str, (ddog_CharSlice){.ptr = data, .len = size});
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

static bool dd_is_http_error(int status) {
    zend_string *str_key;
    ZEND_HASH_FOREACH_STR_KEY(get_DD_TRACE_HTTP_SERVER_ERROR_STATUSES(), str_key) {
        if (str_key) {
            const char *s = ZSTR_VAL(str_key);

            // Range like "500-599"
            int start, end;
            if (sscanf(s, "%d-%d", &start, &end) == 2) {
                if (status >= start && status <= end) {
                    return true;
                }
            } else {
                // Single status code
                int code = atoi(s);
                if (status == code) {
                    return true;
                }
            }
        }
    } ZEND_HASH_FOREACH_END();

    return false;
}

static void dd_set_entrypoint_root_span_props_end(zend_array *meta, int status, struct iter *headers, bool ignore_error) {
    if (status) {
        zend_string *status_str = zend_long_to_str((long)status);
        zval status_zv;
        ZVAL_STR(&status_zv, status_str);
        zend_hash_str_update(meta, ZEND_STRL("http.status_code"), &status_zv);

        // Only check status codes if not ignoring errors
        if (!ignore_error && dd_is_http_error(status)) {
            zval zv = {0}, *value;
            if ((value = zend_hash_str_add(meta, ZEND_STRL("error.type"), &zv))) {
                ZVAL_STR(value, zend_string_init(ZEND_STRL("HttpError"), 0));
            }
        }
    }

    for (zend_string *lowerheader, *headerval; headers->next(headers, &lowerheader, &headerval);) {
        dd_add_header_to_meta(meta, "response", lowerheader, headerval);
        zend_string_release(lowerheader);
        zend_string_release(headerval);
    }
}

static void dd_set_entrypoint_root_rust_span_props_end(ddog_SpanBytes *span, int status, struct iter *headers, bool ignore_error) {
    if (status) {
        zend_string *status_str = zend_long_to_str((long)status);
        ddog_add_str_span_meta_zstr(span, "http.status_code", status_str);
        zend_string_release(status_str);

        if (!ignore_error && dd_is_http_error(status)) {
            if (!ddog_has_span_meta_str(span, "error.type")) {
                ddog_add_str_span_meta_str(span, "error.type", "HttpError");
            }
        }
    }

    for (zend_string *lowerheader, *headerval; headers->next(headers, &lowerheader, &headerval);) {
        dd_add_header_to_rust_span(span, "response", lowerheader, headerval);
        zend_string_release(lowerheader);
        zend_string_release(headerval);
    }
}

static bool should_track_error(zend_object *exception, ddtrace_span_data *span) {
    if (Z_TYPE(span->property_exception) != IS_OBJECT || Z_OBJ(span->property_exception) != exception) {
        return true;
    }

    zval *zv;
    zend_array *meta = ddtrace_property_array(&span->property_meta);

    // Check if error should be ignored or tracking is disabled
    if ((zv = zend_hash_str_find(meta, ZEND_STRL("error.ignored"))) && zval_is_true(zv)) {
        return false;
    }
    if ((zv = zend_hash_str_find(meta, ZEND_STRL("track_error"))) && !zval_is_true(zv)) {
        return false;
    }
    return true;
}

static void _serialize_meta(ddog_SpanBytes *rust_span, ddtrace_span_data *span, zend_string *service_name) {
    bool is_root_span = span->std.ce == ddtrace_ce_root_span_data;
    bool is_inferred_span = span->std.ce == ddtrace_ce_inferred_span_data;
    bool ignore_error = false;

    ddtrace_span_data *inferred_span = NULL;
    if (is_root_span) {
        inferred_span = ddtrace_get_inferred_span(ROOTSPANDATA(&span->std));
    }

    zval *meta = &span->property_meta;
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

                if (!ddog_has_span_meta_zstr(rust_span, str_key)) {
                    dd_serialize_array_meta_recursively(rust_span, str_key, orig_val);
                }
            }
        }
        ZEND_HASH_FOREACH_END();
    }

    zval *existing_env, new_env;
    if ((existing_env = zend_hash_str_find(Z_ARRVAL_P(meta), ZEND_STRL("env")))) {
        LOG(DEPRECATED, "Using \"env\" in meta is deprecated. Instead specify the env property directly on the span.");
    } else {
        ddtrace_convert_to_string(&new_env, &span->property_env);
        if (Z_STRLEN(new_env)) {
            ddog_add_str_span_meta_zstr(rust_span, "env", Z_STR_P(&new_env));
        } else {
            zval_ptr_dtor(&new_env);
            ZVAL_EMPTY_STRING(&new_env);
        }

        existing_env = &new_env;
    }

    if (!span->parent) {
        if (DDTRACE_G(last_flushed_root_env_name)) {
            zend_string_release(DDTRACE_G(last_flushed_root_env_name));
        }
        DDTRACE_G(last_flushed_root_env_name) = zend_string_copy(Z_STR_P(existing_env));
    }

    if (existing_env == &new_env) {
        zval_ptr_dtor(&new_env);
        ZVAL_UNDEF(&new_env);
    }

    zval new_version;
    if (zend_hash_str_exists(Z_ARRVAL_P(meta), ZEND_STRL("version"))) {
        LOG(DEPRECATED, "Using \"version\" in meta is deprecated. Instead specify the version property directly on the span.");
    } else {
        ddtrace_convert_to_string(&new_version, &span->property_version);
        if (Z_STRLEN(new_version)) {
            ddog_add_str_span_meta_zstr(rust_span, "version", Z_STR_P(&new_version));
        }
        zval_ptr_dtor(&new_version);
    }

    zval *exception_zv = &span->property_exception;
    bool has_exception = Z_TYPE_P(exception_zv) == IS_OBJECT && instanceof_function(Z_OBJCE_P(exception_zv), zend_ce_throwable);
    if (has_exception && !ignore_error) {
        enum dd_exception exception_type = DD_EXCEPTION_THROWN;
        if (is_root_span) {
            exception_type = Z_PROP_FLAG_P(exception_zv) == 2 ? DD_EXCEPTION_CAUGHT : DD_EXCEPTION_UNCAUGHT;
        }
        ddtrace_exception_to_meta(Z_OBJ_P(exception_zv), service_name, span->start, rust_span, exception_type);
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
        ddog_add_str_span_meta_zstr(rust_span, "_dd.span_links", buf.s);

        smart_str_free(&buf);

        // Restore the exception
        EG(exception) = current_exception;
    }

    zend_array *span_events = ddtrace_property_array(&span->property_events);
    if (zend_hash_num_elements(span_events) > 0) {
        // Save the current exception, if any, and clear it for php_json_encode_serializable_object not to fail
        // and zend_call_function to actually call the jsonSerialize method
        // Restored after span events are serialized
        zend_object* current_exception = EG(exception);
        EG(exception) = NULL;

        smart_str buf = {0};
        _dd_serialize_json(span_events, &buf, 0);
        ddog_add_str_span_meta_zstr(rust_span, "events", buf.s);

        smart_str_free(&buf);

        // Restore the exception
        EG(exception) = current_exception;
    }

    zval *git_metadata = &span->root->property_git_metadata;
    if (git_metadata && Z_TYPE_P(git_metadata) == IS_OBJECT) {
        ddtrace_git_metadata *metadata = (ddtrace_git_metadata *)Z_OBJ_P(git_metadata);
        if (is_root_span) {
            if (Z_TYPE(metadata->property_commit) == IS_STRING) {
                zend_string *commit_sha = ddtrace_convert_to_str(&metadata->property_commit);
                ddog_add_str_span_meta_zstr(rust_span, "_dd.git.commit.sha", commit_sha);
                zend_string_release(commit_sha);
            }
            if (Z_TYPE(metadata->property_repository) == IS_STRING) {
                zend_string *repository_url = ddtrace_convert_to_str(&metadata->property_repository);
                ddog_add_str_span_meta_zstr(rust_span, "_dd.git.repository_url", repository_url);
                zend_string_release(repository_url);

            }
        }
    }

    if (get_DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED()) { // opt-in
        zend_array *peer_service_sources = ddtrace_property_array(&span->property_peer_service_sources);
        zval *peer_service = zend_hash_str_find(Z_ARRVAL_P(meta), ZEND_STRL("peer.service"));
        if (peer_service && Z_TYPE_P(peer_service) == IS_STRING) { // peer.service is already set by the user, honor it
            ddog_add_str_span_meta_str(rust_span, "_dd.peer.service.source", "peer.service");
            dd_set_mapped_peer_service(rust_span, Z_STR_P(peer_service));
        } else if (zend_hash_num_elements(peer_service_sources) > 0) {
            zval *tag;
            ZEND_HASH_FOREACH_VAL(peer_service_sources, tag)
            {
                if (Z_TYPE_P(tag) == IS_STRING) { // Use the first tag that is found in the span, if any
                    zval *peer_service = zend_hash_find(Z_ARRVAL_P(meta), Z_STR_P(tag));
                    if (peer_service && Z_TYPE_P(peer_service) == IS_STRING) {
                        ddog_add_str_span_meta_zstr(rust_span, "_dd.peer.service.source", Z_STR_P(tag));

                        zend_string *peer = zval_get_string(peer_service);
                        if (!dd_set_mapped_peer_service(rust_span, peer)) {
                            ddog_add_str_span_meta_zstr(rust_span, "peer.service", peer);
                        }

                        break;
                    }
                }
            }
            ZEND_HASH_FOREACH_END();
        }
    }

    if (ddtrace_span_is_entrypoint_root(span) || is_inferred_span) {
        int status = SG(sapi_headers).http_response_code;
        if (ddtrace_active_sapi == DATADOG_PHP_SAPI_FRANKENPHP && !status) {
            status = has_exception ? 500 : 200;
        }
        struct iter *headers = dd_iterate_sapi_headers();
        dd_set_entrypoint_root_rust_span_props_end(rust_span, status, headers, ignore_error);
        efree(headers);
    }

    zval *origin = &span->root->property_origin;
    if (Z_TYPE_P(origin) > IS_NULL && (Z_TYPE_P(origin) != IS_STRING || Z_STRLEN_P(origin))) {
        ddog_add_str_span_meta_zstr(rust_span, "_dd.origin", Z_STR_P(origin));
    }

    bool error = ddog_has_span_meta_str(rust_span, "error.message") ||
                 ddog_has_span_meta_str(rust_span, "error.type");
    if (error && !ignore_error) {
        ddog_set_span_error(rust_span, 1);

        if (!ignore_error && Z_TYPE(span->property_exception) == IS_OBJECT) {
            zend_object *exception = Z_OBJ(span->property_exception);
            ddtrace_span_data *current = span;
            bool should_track;

            do {
                should_track = should_track_error(exception, current);
                if (!should_track) {
                    ddog_add_str_span_meta_str(rust_span, "track_error", "false");
                    break;
                }
                current = current->parent ? SPANDATA(current->parent) : NULL;
            } while (current);
        }
    }

    if (is_inferred_span || (span->root->trace_id.high && is_root_span && !inferred_span)) {
        zend_string *trace_id_str = zend_strpprintf(0, "%" PRIx64, span->root->trace_id.high);
        ddog_add_str_span_meta_zstr(rust_span, "_dd.p.tid", trace_id_str);
        zend_string_release(trace_id_str);
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

    if (!is_inferred_span && !zend_string_equals_ci(Z_STR(prop_service_as_string), Z_STR(prop_root_service_as_string))) {
        ddog_add_str_span_meta_zstr(rust_span, "_dd.base_service", Z_STR_P(&prop_root_service_as_string));
    }

    zend_string_release(Z_STR(prop_root_service_as_string));
    zend_string_release(Z_STR(prop_service_as_string));
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

void transfer_meta_data(ddog_SpanBytes *source, ddog_SpanBytes *destination, const char *key, bool delete_source) {
    ddog_CharSlice value = ddog_get_span_meta_str(source, key);
    if (value.len > 0) {
        ddog_add_str_span_meta_CharSlice(destination, key, value);
        if (delete_source) {
            ddog_del_span_meta_str(source, key);
        }
    }
}

void transfer_metrics_data(ddog_SpanBytes *source, ddog_SpanBytes *destination, const char* key, bool delete_source) {
    double metric;
    if (ddog_get_span_metrics_str(source, key, &metric)) {
        ddog_add_span_metrics_str(destination, key, metric);
        if (delete_source) {
            ddog_del_span_metrics_str(source, key);
        }
    }
}

ddog_SpanBytes *ddtrace_serialize_span_to_rust_span(ddtrace_span_data *span, ddog_TraceBytes *trace) {
    ddog_SpanBytes *rust_span = ddog_trace_new_span(trace);

    bool is_root_span = span->std.ce == ddtrace_ce_root_span_data;
    bool is_inferred_span = span->std.ce == ddtrace_ce_inferred_span_data;

    ddtrace_span_data *inferred_span = NULL;
    if (is_root_span) {
        ddtrace_root_span_data *root_span = ROOTSPANDATA(&span->std);
        inferred_span = ddtrace_get_inferred_span(root_span);
        if (inferred_span) {
            inferred_span->root = root_span;
        }
    }

    ddog_set_span_trace_id(rust_span, span->root->trace_id.low);
    ddog_set_span_id(rust_span, span->span_id);

    if (inferred_span) {
        ddog_set_span_parent_id(rust_span, inferred_span->span_id);
    } else if (span->parent) { // handle dropped spans
        ddtrace_span_data *parent = SPANDATA(span->parent);
        // Ensure the parent id is the root span if everything else was dropped
        while (parent->parent && ddtrace_span_is_dropped(parent)) {
            parent = SPANDATA(parent->parent);
        }
        if (parent) {
            ddog_set_span_parent_id(rust_span, parent->span_id);
        }
    } else if (is_root_span) {
        ddog_set_span_parent_id(rust_span, ROOTSPANDATA(&span->std)->parent_id);
    } else if (is_inferred_span) {
        ddog_set_span_parent_id(rust_span, span->root->parent_id);
    }

    ddog_set_span_start(rust_span, span->start);
    ddog_set_span_duration(rust_span, span->duration);

    zend_array *meta = ddtrace_property_array(&span->property_meta);
    zend_array *metrics = ddtrace_property_array(&span->property_metrics);

    // Remap OTel's status code (metric, http.response.status_code) to DD's status code (meta, http.status_code)
    // OTel HTTP semantic conventions >= 1.21.0
    zval *http_response_status_code = zend_hash_str_find(metrics, ZEND_STRL("http.response.status_code"));
    if (http_response_status_code) {
        zval status_code_as_string;
        ddtrace_convert_to_string(&status_code_as_string, http_response_status_code);
        ddog_add_str_span_meta_zstr(rust_span, "http.status_code", Z_STR_P(&status_code_as_string));
        zend_hash_str_del(metrics, ZEND_STRL("http.response.status_code"));
        zval_ptr_dtor(&status_code_as_string);
    }

    // Remap OTel's status code (metric, http.status_code) to DD's status code (meta, http.status_code)
    // OTel HTTP semantic conventions < 1.21.0
    zval *http_status_code = zend_hash_str_find(metrics, ZEND_STRL("http.status_code"));
    if (http_status_code) {
        if (!ddog_has_span_meta_str(rust_span, "http.status_code")) {
            zval status_code_as_string;
            ddtrace_convert_to_string(&status_code_as_string, http_status_code);
            ddog_add_str_span_meta_zstr(rust_span, "http.status_code", Z_STR_P(&status_code_as_string));
            zval_ptr_dtor(&status_code_as_string);
        }
        zend_hash_str_del(metrics, ZEND_STRL("http.status_code"));
    }

    // SpanData::$name defaults to fully qualified called name (set at span close)
    zval *operation_name = zend_hash_str_find(meta, ZEND_STRL("operation.name"));
    zval *prop_name = &span->property_name;

    if (operation_name) {
        zend_string *lcname = zend_string_tolower(Z_STR_P(operation_name));
        ddog_set_span_name_zstr(rust_span, lcname);
        zend_string_release(lcname);
    } else {
        ZVAL_DEREF(prop_name);
        if (Z_TYPE_P(prop_name) > IS_NULL) {
            zval prop_name_as_string;
            ddtrace_convert_to_string(&prop_name_as_string, prop_name);
            ddog_set_span_name_zstr(rust_span, Z_STR_P(&prop_name_as_string));
            zval_ptr_dtor(&prop_name_as_string);
        }
    }

    // SpanData::$resource defaults to SpanData::$name
    zval *resource_name = zend_hash_str_find(meta, ZEND_STRL("resource.name"));
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
        ddog_set_span_resource_zstr(rust_span, Z_STR_P(&prop_resource_as_string));
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

        ddog_set_span_service_zstr(rust_span, Z_STR_P(&prop_service_as_string));
        zval_ptr_dtor(&prop_service_as_string);
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
        ddog_set_span_type_zstr(rust_span, Z_STR_P(&prop_type_as_string));
    }

    if (span_type) {
        zend_hash_str_del(meta, ZEND_STRL("span.type"));
    }

    zval *analytics_event = zend_hash_str_find(meta, ZEND_STRL("analytics.event"));
    if (analytics_event) {
        if (Z_TYPE_P(analytics_event) == IS_STRING) {
            double parsed_analytics_event = strconv_parse_bool(Z_STR_P(analytics_event));
            if (parsed_analytics_event >= 0) {
                ddog_add_span_metrics_str(rust_span, "_dd1.sr.eausr", parsed_analytics_event);
            }
        } else {
            ddog_add_span_metrics_str(rust_span, "_dd1.sr.eausr", zval_get_double(analytics_event));
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
        LOG(DEBUG, "Notifying profiler of finished local root span.");
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

            ddog_add_span_metrics_str(rust_span, "_dd.span_sampling.mechanism", 8.0);
            ddog_add_span_metrics_str(rust_span, "_dd.span_sampling.rule_rate", sample_rate);

            if (max_per_second_zv) {
                ddog_add_span_metrics_str(rust_span, "_dd.span_sampling.max_per_second", max_per_second);
            }

            break;
        }
        ZEND_HASH_FOREACH_END();
    }

    if (operation_name) {
        zend_hash_str_del(meta, ZEND_STRL("operation.name"));
    }

    _serialize_meta(rust_span, span, Z_TYPE_P(prop_service) > IS_NULL ? Z_STR(prop_service_as_string) : ZSTR_EMPTY_ALLOC());

    zend_string *str_key;
    zval *val;
    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(metrics, str_key, val) {
        if (str_key && !ddog_has_span_metrics_zstr(rust_span, str_key)) {
            dd_serialize_array_metrics_recursively(rust_span, str_key, val);
        }
    } ZEND_HASH_FOREACH_END();

    if ((is_root_span && !inferred_span) || is_inferred_span) {
        if (Z_TYPE_P(&span->root->property_sampling_priority) != IS_UNDEF) {
            long sampling_priority = zval_get_long(&span->root->property_sampling_priority);
            if (!get_global_DD_APM_TRACING_ENABLED() && !ddtrace_trace_source_is_meta_asm_sourced(meta)) {
                sampling_priority = MIN(PRIORITY_SAMPLING_AUTO_KEEP, sampling_priority);
            }
            ddog_add_span_metrics_str(rust_span, "_sampling_priority_v1", sampling_priority);
        }
        if(!get_global_DD_APM_TRACING_ENABLED()) {
            ddog_add_span_metrics_str(rust_span, "_dd.apm.enabled", 0);
        }
    }

    if (ddtrace_span_is_entrypoint_root(span)) {
        if (get_DD_TRACE_MEASURE_COMPILE_TIME()) {
            ddog_add_span_metrics_str(rust_span, "php.compilation.total_time_ms", ddtrace_compile_time_get() / 1000.);
        }
        if (get_DD_TRACE_MEASURE_PEAK_MEMORY_USAGE()) {
            ddog_add_span_metrics_str(rust_span, "php.memory.peak_usage_bytes", zend_memory_peak_usage(false));
            ddog_add_span_metrics_str(rust_span, "php.memory.peak_real_usage_bytes", zend_memory_peak_usage(true));
        }
    }

    if (inferred_span) {
        ddog_SpanBytes *serialized_inferred_span = ddtrace_serialize_span_to_rust_span(inferred_span, trace);

        transfer_metrics_data(rust_span, serialized_inferred_span, "_dd.agent_psr", true);
        transfer_metrics_data(rust_span, serialized_inferred_span, "_dd.rule_psr", true);
        transfer_metrics_data(rust_span, serialized_inferred_span, "_dd.limit_psr", true);

        transfer_meta_data(rust_span, serialized_inferred_span, "error.message", false);
        transfer_meta_data(rust_span, serialized_inferred_span, "error.type", false);
        transfer_meta_data(rust_span, serialized_inferred_span, "error.stack", false);
        transfer_meta_data(rust_span, serialized_inferred_span, "track_error", false);
        transfer_meta_data(rust_span, serialized_inferred_span, "_dd.p.dm", true);
        transfer_meta_data(rust_span, serialized_inferred_span, "_dd.p.tid", true);

        ddog_set_span_error(serialized_inferred_span, ddog_get_span_error(rust_span));
    }

    LOGEV(SPAN, {
        ddog_CharSlice span_log = ddog_span_debug_log(rust_span);
        log("Encoding span: %s", span_log.ptr);
        ddog_free_charslice(span_log);
    });

    zend_array *meta_struct = ddtrace_property_array(&span->property_meta_struct);
    zend_string *ms_str_key;
    zval *ms_val;
    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(meta_struct, ms_str_key, ms_val) {
        if (ms_str_key) {
            dd_serialize_array_meta_struct_recursively(rust_span, ms_str_key, ms_val);
        }
    }
    ZEND_HASH_FOREACH_END();

    return rust_span;
}

zval dd_serialize_rust_traces_to_zval(ddog_TracesBytes *traces) {
    zval traces_zv;
    array_init(&traces_zv);

    for (size_t i = 0; i < ddog_get_traces_size(traces); i++) {
        ddog_TraceBytes *trace = ddog_get_trace(traces, i);
        zval trace_zv;
        array_init(&trace_zv);

        for (size_t j = 0; j < ddog_get_trace_size(trace); j++) {
            ddog_SpanBytes *span = ddog_get_span(trace, j);
            zval span_zv;
            array_init(&span_zv);

            add_assoc_str(&span_zv, KEY_TRACE_ID, ddtrace_span_id_as_string(ddog_get_span_trace_id(span)));
            add_assoc_str(&span_zv, KEY_SPAN_ID, ddtrace_span_id_as_string(ddog_get_span_id(span)));

            size_t span_parent_id = ddog_get_span_parent_id(span);
            if (span_parent_id) {
                add_assoc_str(&span_zv, KEY_PARENT_ID, ddtrace_span_id_as_string(span_parent_id));
            }

            add_assoc_long(&span_zv, "start", ddog_get_span_start(span));
            add_assoc_long(&span_zv, "duration", ddog_get_span_duration(span));

            ddog_CharSlice name = ddog_get_span_name(span);
            add_assoc_str(&span_zv, "name", dd_CharSlice_to_zend_string(name));

            ddog_CharSlice resource = ddog_get_span_resource(span);
            add_assoc_str(&span_zv, "resource", dd_CharSlice_to_zend_string(resource));

            ddog_CharSlice service = ddog_get_span_service(span);
            add_assoc_str(&span_zv, "service", dd_CharSlice_to_zend_string(service));

            ddog_CharSlice type = ddog_get_span_type(span);
            add_assoc_str(&span_zv, "type", dd_CharSlice_to_zend_string(type));

            double error = ddog_get_span_error(span);
            if (error != 0) {
                add_assoc_long(&span_zv, "error", error);
            }

            size_t meta_count = 0;
            ddog_CharSlice *meta_keys = ddog_span_meta_get_keys(span, &meta_count);

            if (meta_count > 0) {
                zval meta_zv;
                array_init(&meta_zv);

                for (size_t k = 0; k < meta_count; k++) {
                    ddog_CharSlice key = meta_keys[k];
                    ddog_CharSlice value = ddog_get_span_meta(span, key);

                    zval value_zv;
                    ZVAL_STR(&value_zv, dd_CharSlice_to_zend_string(value));
                    zend_hash_str_update(Z_ARR(meta_zv), key.ptr, key.len, &value_zv);
                }

                add_assoc_zval(&span_zv, "meta", &meta_zv);
            }

            size_t metrics_count = 0;
            ddog_CharSlice *metrics_keys = ddog_span_metrics_get_keys(span, &metrics_count);

            if (metrics_count > 0) {
                zval metrics_zv;
                array_init(&metrics_zv);

                for (size_t k = 0; k < metrics_count; k++) {
                    ddog_CharSlice key = metrics_keys[k];
                    double value;

                    if (ddog_get_span_metrics(span, key, &value)) {
                        zval value_zv;
                        ZVAL_DOUBLE(&value_zv, value);
                        zend_hash_str_update(Z_ARR(metrics_zv), key.ptr, key.len, &value_zv);
                    }
                }

                add_assoc_zval(&span_zv, "metrics", &metrics_zv);
            }

            size_t meta_struct_count = 0;
            ddog_CharSlice *meta_struct_keys = ddog_span_meta_struct_get_keys(span, &meta_struct_count);

            if (meta_struct_count > 0) {
                zval meta_struct_zv;
                array_init(&meta_struct_zv);

                for (size_t k = 0; k < meta_struct_count; k++) {
                    ddog_CharSlice key = meta_struct_keys[k];
                    ddog_CharSlice value = ddog_get_span_meta_struct(span, key);

                    zval value_zv;
                    ZVAL_STR(&value_zv, dd_CharSlice_to_zend_string(value));
                    zend_hash_str_update(Z_ARR(meta_struct_zv), key.ptr, key.len, &value_zv);
                }

                add_assoc_zval(&span_zv, "meta_struct", &meta_struct_zv);
            }

            zend_hash_next_index_insert_new(Z_ARR_P(&trace_zv), &span_zv);

            ddog_span_free_keys_ptr(meta_keys, meta_count);
            ddog_span_free_keys_ptr(metrics_keys, metrics_count);
            ddog_span_free_keys_ptr(meta_struct_keys, meta_struct_count);
        }

        zend_hash_next_index_insert_new(Z_ARR_P(&traces_zv), &trace_zv);
    }

    return traces_zv;
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

