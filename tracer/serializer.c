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
#include <components-rs/datadog.h>
#include <components-rs/sidecar.h>
#include <SAPI.h>
#include <exceptions/exceptions.h>

#include <ext/process_tags.h>
#ifndef _WIN32
#include <stdatomic.h>
#else
#include <components/atomic_win32_polyfill.h>
#include <synchapi.h>
#endif
#include "vendor/mpack/mpack.h"
#include <zai_string/string.h>
#include <sandbox/sandbox.h>
#include <ext/standard/url.h>

#include <ext/string_utils.h>
#include "ddtrace.h"
#include "engine_api.h"
#include "engine_hooks.h"
#include "git_metadata.h"
#include "ip_extraction.h"
#include <components/log/log.h>
#include "priority_sampling/priority_sampling.h"
#include "random.h"
#include "span.h"
#include "uri_normalization.h"
#include "user_request.h"
#include "rule_matching.h"
#include <ext/zend_hrtime.h>
#include "trace_source.h"
#include "exception_serialize.h"
#include <ext/ffi_utils.h>
#include "span_stats.h"
#include "trace_filter.h"
#include "configuration.h"

ZEND_EXTERN_MODULE_GLOBALS(datadog);

#define DD_TAG_HTTP_REQH_ENDPOINT_SCAN "http.request.headers.x-datadog-endpoint-scan"
#define DD_TAG_HTTP_REQH_SECURITY_TEST "http.request.headers.x-datadog-security-test"

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

void dd_span_attr_str(ddog_SpanNode *span, const char *key, const char *val) {
    ddog_add_span_attr_lit_cs(span, key, (ddog_CharSlice){ .ptr = val, .len = strlen(val) });
}
void dd_span_attr_zstr(ddog_SpanNode *span, const char *key, zend_string *val) {
    ddog_add_span_attr_lit_cs(span, key, dd_zend_string_to_CharSlice(val));
}

static void dd_add_header_to_rust_span(ddog_SpanNode *span, const char *type, zend_string *lowerheader,
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

        ddog_add_span_attr_zstr_zstr(span, headertag, headerval);
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
    zend_array *meta = ddtrace_property_array(&span->property_attributes);
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

static bool dd_set_mapped_peer_service(ddog_SpanNode *span, zend_string *peer_service) {
    zend_array *peer_service_mapping = get_DD_TRACE_PEER_SERVICE_MAPPING();
    if (zend_hash_num_elements(peer_service_mapping) == 0 || !peer_service) {
        return false;
    }

    zval* mapped_service_zv = zend_hash_find(peer_service_mapping, peer_service);
    if (mapped_service_zv) {
        zend_string *mapped_service = zval_get_string(mapped_service_zv);
        dd_span_attr_zstr(span, "peer.service.remapped_from", peer_service);
        dd_span_attr_zstr(span, "peer.service", mapped_service);
        zend_string_release(mapped_service);
        return true;
    }

    return false;
}

void ddtrace_update_root_id_properties(ddtrace_root_span_data *span) {
    zval zv;
    ZVAL_STR(&zv, datadog_trace_id_as_hex_string(span->trace_id));
    datadog_assign_variable(&span->property_trace_id, &zv);
    if (span->parent_id) {
        ZVAL_STR(&zv, ddtrace_span_id_as_string(span->parent_id));
    } else {
        ZVAL_UNDEF(&zv);
    }
    datadog_assign_variable(&span->property_parent_id, &zv);
}

struct superglob_equiv {
    zend_array *server;
    zend_array *post;
};

static void dd_set_entrypoint_root_span_props(struct superglob_equiv *data, ddtrace_root_span_data *span) {
    zend_array *attributes = ddtrace_property_array(&span->property_attributes);

    if (data->server){
        zend_string *http_url = dd_build_req_url(data->server);
        if (ZSTR_LEN(http_url) > 0) {
            zval http_url_zv;
            ZVAL_STR(&http_url_zv, http_url);
            zend_hash_str_add_new(attributes, ZEND_STRL("http.url"), &http_url_zv);
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
        zend_hash_str_add_new(attributes, ZEND_STRL("http.method"), &http_method);

        // Mark HTTP server entry spans with span.kind=server for client-side stats aggregation.
        // Written as a tag add-if-absent (not via the property) so a userland/OTel value wins.
        zval span_kind_server;
        ZVAL_STRING(&span_kind_server, "server");
        if (!zend_hash_str_add(attributes, ZEND_STRL("span.kind"), &span_kind_server)) {
            zval_ptr_dtor(&span_kind_server);
        }

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
        ddtrace_extract_ip_from_headers(&server_zv, attributes);
    }

    zend_string *user_agent = dd_get_user_agent(data->server);
    if (user_agent && ZSTR_LEN(user_agent) > 0) {
        zval http_useragent;
        ZVAL_STR_COPY(&http_useragent, user_agent);
        zend_hash_str_add_new(attributes, ZEND_STRL("http.useragent"), &http_useragent);
    }

    zend_string *referrer_host = dd_get_referrer_host(data->server);
    if (referrer_host && ZSTR_LEN(referrer_host) > 0) {
        zval http_referrer_host;
        ZVAL_STR(&http_referrer_host, referrer_host);
        zend_hash_str_update(attributes, ZEND_STRL("http.referrer_hostname"), &http_referrer_host);
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

                dd_add_header_to_meta(attributes, "request", lowerheader, Z_STR_P(headerval));
                zend_string_release(lowerheader);
            }
        }
        ZEND_HASH_FOREACH_END();
    }

    if (data->post && zend_hash_num_elements(get_DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED())) {
        zval post_zv;
        ZVAL_ARR(&post_zv, data->post);
        zend_string *empty = ZSTR_EMPTY_ALLOC();
        dd_add_post_fields_to_meta_recursive(attributes, "request", empty, &post_zv, get_DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED(), false);
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

    zval *parent_svc_src = ddtrace_span_find_tag(parent, ZEND_STRL("_dd.svc_src"));
    if (parent_svc_src) {
        zend_array *child_meta = ddtrace_property_array(&span->property_attributes);
        Z_TRY_ADDREF_P(parent_svc_src);
        zend_hash_str_update(child_meta, ZEND_STRL("_dd.svc_src"), parent_svc_src);
    }

    zval *prop_version = &span->property_version;
    zval_ptr_dtor(prop_version);
    zval *version;
    if ((version = ddtrace_span_find_tag(parent, ZEND_STRL("version")))) {
        zval old = *version;
        datadog_convert_to_string(version, version);
        zval_ptr_dtor(&old);
    } else {
        version = &parent->property_version;
    }
    ZVAL_COPY_DEREF(prop_version, version);

    zval *prop_env = &span->property_env;
    zval_ptr_dtor(prop_env);
    zval *env;
    if ((env = ddtrace_span_find_tag(parent, ZEND_STRL("env")))) {
        zval old = *env;
        datadog_convert_to_string(env, env);
        zval_ptr_dtor(&old);
    } else {
        env = &parent->property_env;
    }
    ZVAL_COPY_DEREF(prop_env, env);
}

zend_string *ddtrace_active_service_name(void) {
    ddtrace_span_data *span = ddtrace_active_span();
    if (span) {
        return datadog_convert_to_str(&span->property_service);
    }
    zend_string *ini_service = get_DD_SERVICE();
    if (ZSTR_LEN(ini_service)) {
        return zend_string_copy(ini_service);
    }
    return datadog_default_service_name();
}

void ddtrace_set_root_span_properties(ddtrace_root_span_data *span) {
    ddtrace_update_root_id_properties(span);

    span->sampling_rule.rule = INT32_MAX;

    zend_array *attributes = ddtrace_property_array(&span->property_attributes);
    zend_hash_copy(attributes, &DDTRACE_G(root_span_tags_preset), (copy_ctor_func_t)zval_add_ref);

    /* Compilers rightfully complain about array bounds due to the struct
     * hack if we write straight to the char storage. Saving the char* to a
     * temporary avoids the warning without a performance penalty.
     */
    zend_string *encoded_id = zend_string_alloc(36, false);
    datadog_format_runtime_id((uint8_t(*)[36])&ZSTR_VAL(encoded_id));
    ZSTR_VAL(encoded_id)[36] = '\0';

    zval zv;
    ZVAL_STR(&zv, encoded_id);
    zend_hash_str_update(attributes, ZEND_STRL("runtime-id"), &zv);

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
        if (ZSTR_LEN(get_DD_HOSTNAME())) {
            zval hostname_zv;
            ZVAL_STR_COPY(&hostname_zv, get_DD_HOSTNAME());
            zend_hash_str_update(attributes, ZEND_STRL("_dd.hostname"), &hostname_zv);
        } else {

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
                zend_hash_str_update(attributes, ZEND_STRL("_dd.hostname"), &hostname_zv);
            }
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
        ZVAL_STR(prop_name, datadog_default_service_name());
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

        if (DATADOG_G(asm_event_emitted)) {
            span->asm_event_emitted = DATADOG_G(asm_event_emitted);
            DATADOG_G(asm_event_emitted) = false; // we attach this to the first root span after the asm event was detected (if there was none while emitted)
        }

        if (get_DD_TRACE_GIT_METADATA_ENABLED()) {
            ddtrace_inject_git_metadata(&span->property_git_metadata);
        }
    }

    zval pid;
    ZVAL_DOUBLE(&pid, (double)getpid());
    zend_hash_str_update(attributes, ZEND_STRL("process_id"), &pid);
}

// Backing array of an array/object value; sets *release when the caller must
// zend_release_properties() afterwards (object properties on PHP >= 7.4).
static zend_array *dd_native_props(zval *value, bool *release) {
    *release = false;
    if (Z_TYPE_P(value) == IS_OBJECT) {
#if PHP_VERSION_ID >= 70400
        *release = true;
        return zend_get_properties_for(value, ZEND_PROP_PURPOSE_JSON);
#else
        return Z_OBJPROP_P(value);
#endif
    }
    return Z_ARR_P(value);
}

static inline void dd_native_release_props(zend_array *arr, bool release) {
#if PHP_VERSION_ID >= 70400
    if (release) {
        zend_release_properties(arr);
    }
#else
    (void)arr; (void)release;
#endif
}

// Immutable arrays (opcache SHM, the empty array) must never be written to and cannot form a cycle.
static inline bool dd_native_is_immutable(zval *value) {
    return Z_TYPE_P(value) == IS_ARRAY && (GC_FLAGS(Z_ARR_P(value)) & IS_ARRAY_IMMUTABLE);
}

// Marks array/object `value` while it is serialized, so Z_IS_RECURSIVE_P detects a reference cycle back
// to it. Objects are marked on the object: get_properties_for may build a fresh table per call (DateTime).
// Mirrors php_var_dump: the value is also held (addref) while being iterated.
static inline void dd_native_protect_recursion(zval *value) {
    if (!dd_native_is_immutable(value)) {
        Z_ADDREF_P(value);
        Z_PROTECT_RECURSION_P(value);
    }
}

static inline void dd_native_unprotect_recursion(zval *value) {
    if (!dd_native_is_immutable(value)) {
        Z_UNPROTECT_RECURSION_P(value);
        GC_DTOR_NO_REF(Z_COUNTED_P(value));
    }
}

// A packed PHP array nests as a list; any other array, and objects, nest as a map.
static inline bool dd_native_is_list(zval *value) {
    return Z_TYPE_P(value) == IS_ARRAY && zend_array_is_list(Z_ARR_P(value));
}

// Map member key for `arr` entry (`str_key`, `num_key`): numeric keys as decimal into `numbuf`.
static inline ddog_CharSlice dd_native_member_key(zend_string *str_key, zend_ulong num_key, char numbuf[24]) {
    return str_key
        ? dd_zend_string_to_CharSlice(str_key)
        : (ddog_CharSlice){ .ptr = numbuf, .len = snprintf(numbuf, 24, ZEND_ULONG_FMT, num_key) };
}

// Protected and private object members are mangled with a leading NUL byte.
static inline bool dd_native_is_hidden_member(zend_string *str_key) {
    return str_key && ZSTR_LEN(str_key) > 0 && ZSTR_VAL(str_key)[0] == '\0';
}

// --- Native nested attributes for LINKS and EVENTS ---------------------------------------------
//
// Unlike the span path (which buckets leaves into meta strings / metric doubles), link/event leaves
// keep their PHP scalar type; objects become a map of their public properties.

static void dd_native_typed_emit_list(ddog_AttrList *dest, zval *value);
static void dd_native_typed_emit_map(ddog_AttrMap *dest, ddog_CharSlice key, zval *value);

// Builds the list for packed array `value`.
static ddog_AttrList *dd_native_typed_build_list(zval *value) {
    zend_array *arr = Z_ARR_P(value);
    ddog_AttrList *list = ddog_attr_list_new(zend_hash_num_elements(arr));
    dd_native_protect_recursion(value);
    zval *val;
    ZEND_HASH_FOREACH_VAL_IND(arr, val) {
        dd_native_typed_emit_list(list, val);
    } ZEND_HASH_FOREACH_END();
    dd_native_unprotect_recursion(value);
    return list;
}

// Builds the map for array/object `value` (numeric keys as decimal, matching PHP's json object keys).
static ddog_AttrMap *dd_native_typed_build_map(zval *value) {
    bool release;
    zend_array *arr = dd_native_props(value, &release);
    ddog_AttrMap *map = ddog_attr_map_new(zend_hash_num_elements(arr));
    dd_native_protect_recursion(value);
    zval *val;
    zend_string *str_key;
    zend_ulong num_key;
    ZEND_HASH_FOREACH_KEY_VAL_IND(arr, num_key, str_key, val) {
        if (!dd_native_is_hidden_member(str_key)) {
            char numbuf[24];
            dd_native_typed_emit_map(map, dd_native_member_key(str_key, num_key, numbuf), val);
        }
    } ZEND_HASH_FOREACH_END();
    dd_native_unprotect_recursion(value);
    dd_native_release_props(arr, release);
    return map;
}

// String form of a scalar leaf (top-level null -> "null", as before).
static zend_string *dd_native_typed_str(zval *value) {
    return datadog_convert_to_str(value);
}

// Emits one list element, preserving the PHP scalar type. A recursive array/object (a reference
// cycle) becomes the "" leaf instead of being nested.
static void dd_native_typed_emit_list(ddog_AttrList *dest, zval *value) {
    ZVAL_DEREF(value);
    switch (Z_TYPE_P(value)) {
        case IS_ARRAY:
        case IS_OBJECT:
            if (Z_IS_RECURSIVE_P(value)) {
                ddog_attr_list_push_str(dest, DDOG_CHARSLICE_C(""));
            } else if (dd_native_is_list(value)) {
                ddog_attr_list_push_list(dest, dd_native_typed_build_list(value));
            } else {
                ddog_attr_list_push_map(dest, dd_native_typed_build_map(value));
            }
            break;
        case IS_NULL:
            break; // V1 has no null value: nested nulls are skipped
        case IS_TRUE:   ddog_attr_list_push_bool(dest, true); break;
        case IS_FALSE:  ddog_attr_list_push_bool(dest, false); break;
        case IS_LONG:   ddog_attr_list_push_int(dest, Z_LVAL_P(value)); break;
        case IS_DOUBLE: ddog_attr_list_push_double(dest, Z_DVAL_P(value)); break;
        default: {
            zend_string *str = dd_native_typed_str(value);
            ddog_attr_list_push_str(dest, dd_zend_string_to_CharSlice(str));
            zend_string_release(str);
            break;
        }
    }
}

// Map counterpart of dd_native_typed_emit_list.
static void dd_native_typed_emit_map(ddog_AttrMap *dest, ddog_CharSlice key, zval *value) {
    ZVAL_DEREF(value);
    switch (Z_TYPE_P(value)) {
        case IS_ARRAY:
        case IS_OBJECT:
            if (Z_IS_RECURSIVE_P(value)) {
                ddog_attr_map_put_str(dest, key, DDOG_CHARSLICE_C(""));
            } else if (dd_native_is_list(value)) {
                ddog_attr_map_put_list(dest, key, dd_native_typed_build_list(value));
            } else {
                ddog_attr_map_put_map(dest, key, dd_native_typed_build_map(value));
            }
            break;
        case IS_NULL:
            break;
        case IS_TRUE:   ddog_attr_map_put_bool(dest, key, true); break;
        case IS_FALSE:  ddog_attr_map_put_bool(dest, key, false); break;
        case IS_LONG:   ddog_attr_map_put_int(dest, key, Z_LVAL_P(value)); break;
        case IS_DOUBLE: ddog_attr_map_put_double(dest, key, Z_DVAL_P(value)); break;
        default: {
            zend_string *str = dd_native_typed_str(value);
            ddog_attr_map_put_str(dest, key, dd_zend_string_to_CharSlice(str));
            zend_string_release(str);
            break;
        }
    }
}

// Emit each SpanLink into the V1 builder span, reading from the PHP link objects (scalar attributes
// are strings; dropped_attributes_count has no PHP-side source).
static void dd_span_links_to_rust(zend_array *links, ddog_SpanNode *span) {
    zval *val;
    ZEND_HASH_FOREACH_VAL(links, val) {
        ZVAL_DEREF(val);
        if (Z_TYPE_P(val) != IS_OBJECT || !instanceof_function(Z_OBJCE_P(val), ddtrace_ce_span_link)) {
            continue;
        }
        ddtrace_span_link *link = (ddtrace_span_link *)Z_OBJ_P(val);
        ddog_SpanLinkBytes *rust_link = ddog_new_link(span);

        zval *tid = &link->property_trace_id;
        if (Z_TYPE_P(tid) == IS_STRING) {
            datadog_trace_id id = ddtrace_parse_hex_trace_id(Z_STRVAL_P(tid), Z_STRLEN_P(tid));
            ddog_link_set_trace_id(rust_link, id.high, id.low);
        }
        ddog_link_set_span_id(rust_link, ddtrace_parse_hex_span_id(&link->property_span_id));

        zval *ts = &link->property_trace_state;
        if (Z_TYPE_P(ts) == IS_STRING && Z_STRLEN_P(ts) > 0) {
            ddog_link_set_tracestate(rust_link, dd_zend_string_to_CharSlice(Z_STR_P(ts)));
        }

        zval *attrs = &link->property_attributes;
        ZVAL_DEREF(attrs);
        if (Z_TYPE_P(attrs) == IS_ARRAY) {
            zend_ulong idx;
            zend_string *key;
            zval *aval;
            ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(attrs), idx, key, aval) {
                char numbuf[24];
                ddog_CharSlice key_cs = key
                    ? dd_zend_string_to_CharSlice(key)
                    : (ddog_CharSlice){ .ptr = numbuf, .len = snprintf(numbuf, sizeof(numbuf), ZEND_ULONG_FMT, idx) };
                ZVAL_DEREF(aval);
                if (Z_TYPE_P(aval) == IS_ARRAY || Z_TYPE_P(aval) == IS_OBJECT) {
                    // A reference cycle becomes the "" leaf instead of being nested.
                    if (Z_IS_RECURSIVE_P(aval)) {
                        ddog_link_add_attr_str(rust_link, key_cs, DDOG_CHARSLICE_C(""));
                    } else if (dd_native_is_list(aval)) {
                        ddog_link_attr_set_list(rust_link, key_cs, dd_native_typed_build_list(aval));
                    } else {
                        ddog_link_attr_set_map(rust_link, key_cs, dd_native_typed_build_map(aval));
                    }
                } else {
                    // Top-level link scalars are strings.
                    zend_string *str = dd_native_typed_str(aval);
                    ddog_link_add_attr_str(rust_link, key_cs, dd_zend_string_to_CharSlice(str));
                    zend_string_release(str);
                }
            } ZEND_HASH_FOREACH_END();
        }
    } ZEND_HASH_FOREACH_END();
}

static void dd_event_attribute_to_rust(ddog_SpanEventBytes *event, ddog_CharSlice key, zval *val) {
    ZVAL_DEREF(val);
    if (Z_TYPE_P(val) == IS_ARRAY || Z_TYPE_P(val) == IS_OBJECT) {
        // A reference cycle becomes the "" leaf instead of being nested.
        if (Z_IS_RECURSIVE_P(val)) {
            ddog_event_add_attr_str(event, key, DDOG_CHARSLICE_C(""));
        } else if (dd_native_is_list(val)) {
            ddog_event_attr_set_list(event, key, dd_native_typed_build_list(val));
        } else {
            ddog_event_attr_set_map(event, key, dd_native_typed_build_map(val));
        }
        return;
    }
    switch (Z_TYPE_P(val)) {
        case IS_TRUE:   ddog_event_add_attr_bool(event, key, true); break;
        case IS_FALSE:  ddog_event_add_attr_bool(event, key, false); break;
        case IS_LONG:   ddog_event_add_attr_int(event, key, Z_LVAL_P(val)); break;
        case IS_DOUBLE: ddog_event_add_attr_double(event, key, Z_DVAL_P(val)); break;
        default: {
            zend_string *str = dd_native_typed_str(val);
            ddog_event_add_attr_str(event, key, dd_zend_string_to_CharSlice(str));
            zend_string_release(str);
            break;
        }
    }
}

// Emit each SpanEvent into the V1 builder span, dispatching attributes by type. ExceptionSpanEvent
// flattens exception.message/type/stacktrace as string attributes.
static void dd_span_events_to_rust(zend_array *events, ddog_SpanNode *span) {
    zval *val;
    ZEND_HASH_FOREACH_VAL(events, val) {
        ZVAL_DEREF(val);
        if (Z_TYPE_P(val) != IS_OBJECT || !instanceof_function(Z_OBJCE_P(val), ddtrace_ce_span_event)) {
            continue;
        }
        ddtrace_span_event *event = (ddtrace_span_event *)Z_OBJ_P(val);
        ddog_SpanEventBytes *rust_event = ddog_new_event(span);

        zval *name = &event->property_name;
        if (Z_TYPE_P(name) == IS_STRING) {
            ddog_event_set_name(rust_event, dd_zend_string_to_CharSlice(Z_STR_P(name)));
        }
        zval *time = &event->property_timestamp;
        ZVAL_DEREF(time);
        if (Z_TYPE_P(time) == IS_LONG) {
            ddog_event_set_time(rust_event, (uint64_t)Z_LVAL_P(time));
        }

        if (instanceof_function(event->std.ce, ddtrace_ce_exception_span_event)) {
            ddtrace_exception_span_event *exc_event = (ddtrace_exception_span_event *)event;
            zval *exception = &exc_event->property_exception;
            if (Z_TYPE_P(exception) == IS_OBJECT && instanceof_function(Z_OBJCE_P(exception), zend_ce_throwable)) {
                zend_string *message = zai_exception_message(Z_OBJ_P(exception));
                if (ZSTR_LEN(message)) {
                    ddog_event_add_attr_str(rust_event,
                        DDOG_CHARSLICE_C("exception.message"), dd_zend_string_to_CharSlice(message));
                }
                ddog_event_add_attr_str(rust_event,
                    DDOG_CHARSLICE_C("exception.type"), dd_zend_string_to_CharSlice(Z_OBJCE_P(exception)->name));
                zend_string *stacktrace = zai_get_trace_without_args_from_exception(Z_OBJ_P(exception));
                ddog_event_add_attr_str(rust_event,
                    DDOG_CHARSLICE_C("exception.stacktrace"), dd_zend_string_to_CharSlice(stacktrace));
                zend_string_release(stacktrace);
            }
        }

        zval *attrs = &event->property_attributes;
        ZVAL_DEREF(attrs);
        if (Z_TYPE_P(attrs) == IS_ARRAY) {
            zend_ulong idx;
            zend_string *key;
            zval *aval;
            ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(attrs), idx, key, aval) {
                char numbuf[24];
                ddog_CharSlice key_cs = key
                    ? dd_zend_string_to_CharSlice(key)
                    : (ddog_CharSlice){ .ptr = numbuf, .len = snprintf(numbuf, sizeof(numbuf), ZEND_ULONG_FMT, idx) };
                dd_event_attribute_to_rust(rust_event, key_cs, aval);
            } ZEND_HASH_FOREACH_END();
        }
    } ZEND_HASH_FOREACH_END();
}

// Native V1 nested-attribute serialization. A PHP array/object value becomes a native V1
// `List`/`KeyValue` (built bottom-up via the owned-container FFI) instead of C-side dotted-key
// flattening; libdatadog's v0.4 downgrade re-flattens `List`/`KeyValue` to the identical dotted
// `key.<i>` / `key.<member>` entries, so the v0.4 wire is byte-for-byte unchanged. To keep that
// parity exact, leaves follow the bucket (not the PHP scalar type): the meta path
// (`to_double == false`) emits `String` leaves via `datadog_convert_to_string`, the metrics path
// emits `double` leaves via `zval_get_double` — matching what the old flatten wrote. Empty and
// recursion-guarded arrays emit the same `""` / `0.0` scalar placeholder the old code did.
//
// Not merged with the link/event builders: here an empty array/object folds into the same NULL
// build result as a recursive one (both need the placeholder), whereas links/events nest an empty
// array as an empty List/KeyValue.

static void dd_native_attr_emit_list(ddog_AttrList *dest, zval *value, bool to_double);
static void dd_native_attr_emit_map(ddog_AttrMap *dest, ddog_CharSlice key, zval *value, bool to_double);

// Builds the list for packed array `value`; NULL when it is empty or already being serialized, in
// which case the caller emits the scalar placeholder.
static ddog_AttrList *dd_native_attr_build_list(zval *value, bool to_double) {
    zend_array *arr = Z_ARR_P(value);
    if (!zend_hash_num_elements(arr) || Z_IS_RECURSIVE_P(value)) {
        return NULL;
    }
    ddog_AttrList *list = ddog_attr_list_new(zend_hash_num_elements(arr));
    dd_native_protect_recursion(value);
    zval *val;
    ZEND_HASH_FOREACH_VAL_IND(arr, val) {
        dd_native_attr_emit_list(list, val, to_double);
    } ZEND_HASH_FOREACH_END();
    dd_native_unprotect_recursion(value);
    return list;
}

// Map counterpart of dd_native_attr_build_list (numeric keys as their decimal form, matching the old
// dotted keys).
static ddog_AttrMap *dd_native_attr_build_map(zval *value, bool to_double) {
    bool release;
    zend_array *arr = dd_native_props(value, &release);
    ddog_AttrMap *map = NULL;
    if (zend_hash_num_elements(arr) && !Z_IS_RECURSIVE_P(value)) {
        map = ddog_attr_map_new(zend_hash_num_elements(arr));
        dd_native_protect_recursion(value);
        zval *val;
        zend_string *str_key;
        zend_ulong num_key;
        ZEND_HASH_FOREACH_KEY_VAL_IND(arr, num_key, str_key, val) {
            if (!dd_native_is_hidden_member(str_key)) {
                char numbuf[24];
                dd_native_attr_emit_map(map, dd_native_member_key(str_key, num_key, numbuf), val, to_double);
            }
        } ZEND_HASH_FOREACH_END();
        dd_native_unprotect_recursion(value);
    }
    dd_native_release_props(arr, release);
    return map;
}

// Emits one list element: arrays/objects nest a child; scalars and empty/recursive arrays a leaf.
static void dd_native_attr_emit_list(ddog_AttrList *dest, zval *value, bool to_double) {
    ZVAL_DEREF(value);
    if (Z_TYPE_P(value) == IS_ARRAY || Z_TYPE_P(value) == IS_OBJECT) {
        if (dd_native_is_list(value)) {
            ddog_AttrList *child = dd_native_attr_build_list(value, to_double);
            if (child) {
                ddog_attr_list_push_list(dest, child);
                return;
            }
        } else {
            ddog_AttrMap *child = dd_native_attr_build_map(value, to_double);
            if (child) {
                ddog_attr_list_push_map(dest, child);
                return;
            }
        }
        if (to_double) {
            ddog_attr_list_push_double(dest, 0.0);
        } else {
            ddog_attr_list_push_str(dest, DDOG_CHARSLICE_C(""));
        }
    } else if (to_double) {
        ddog_attr_list_push_double(dest, zval_get_double(value));
    } else {
        zval val_as_string;
        datadog_convert_to_string(&val_as_string, value);
        ddog_attr_list_push_str(dest, dd_zend_string_to_CharSlice(Z_STR(val_as_string)));
        zval_ptr_dtor(&val_as_string);
    }
}

// Map counterpart of dd_native_attr_emit_list.
static void dd_native_attr_emit_map(ddog_AttrMap *dest, ddog_CharSlice key, zval *value, bool to_double) {
    ZVAL_DEREF(value);
    if (Z_TYPE_P(value) == IS_ARRAY || Z_TYPE_P(value) == IS_OBJECT) {
        if (dd_native_is_list(value)) {
            ddog_AttrList *child = dd_native_attr_build_list(value, to_double);
            if (child) {
                ddog_attr_map_put_list(dest, key, child);
                return;
            }
        } else {
            ddog_AttrMap *child = dd_native_attr_build_map(value, to_double);
            if (child) {
                ddog_attr_map_put_map(dest, key, child);
                return;
            }
        }
        if (to_double) {
            ddog_attr_map_put_double(dest, key, 0.0);
        } else {
            ddog_attr_map_put_str(dest, key, DDOG_CHARSLICE_C(""));
        }
    } else if (to_double) {
        ddog_attr_map_put_double(dest, key, zval_get_double(value));
    } else {
        zval val_as_string;
        datadog_convert_to_string(&val_as_string, value);
        ddog_attr_map_put_str(dest, key, dd_zend_string_to_CharSlice(Z_STR(val_as_string)));
        zval_ptr_dtor(&val_as_string);
    }
}

// Top-level entry: serializes `value` as the span attribute named `str`. A non-empty array/object
// attaches a native `List`/`KeyValue`; scalars and empty/recursive arrays fall back to the existing
// scalar attribute setters (byte-identical to the old flatten's top-level output).
static void dd_native_attr_top(ddog_SpanNode *target, zend_string *str, zval *value, bool to_double) {
    ZVAL_DEREF(value);

    if (Z_TYPE_P(value) == IS_ARRAY || Z_TYPE_P(value) == IS_OBJECT) {
        ddog_CharSlice key_cs = dd_zend_string_to_CharSlice(str);
        if (dd_native_is_list(value)) {
            ddog_AttrList *list = dd_native_attr_build_list(value, to_double);
            if (list) {
                ddog_span_attr_set_list(target, key_cs, list);
                return;
            }
        } else {
            ddog_AttrMap *map = dd_native_attr_build_map(value, to_double);
            if (map) {
                ddog_span_attr_set_map(target, key_cs, map);
                return;
            }
        }
        if (to_double) {
            ddog_add_span_attr_double_zstr(target, str, 0.0);
        } else {
            ddog_add_span_attr_zstr_cs(target, str, DDOG_CHARSLICE_C(""));
        }
    } else if (to_double) {
        ddog_add_span_attr_double_zstr(target, str, zval_get_double(value));
    } else {
        zval val_as_string;
        datadog_convert_to_string(&val_as_string, value);
        ddog_add_span_attr_zstr_zstr(target, str, Z_STR_P(&val_as_string));
        zval_ptr_dtor(&val_as_string);
    }
}

static void dd_serialize_array_meta_recursively(ddog_SpanNode *target, zend_string *str, zval *value) {
    dd_native_attr_top(target, str, value, false);
}

static void dd_serialize_array_metrics_recursively(ddog_SpanNode *target, zend_string *str, zval *value) {
    dd_native_attr_top(target, str, value, true);
}

// SpanData::$attributes entry: keeps the PHP type like link/event attributes (null is skipped).
static void dd_serialize_typed_attribute(ddog_SpanNode *target, zend_string *key, zval *value) {
    ZVAL_DEREF(value);
    ddog_CharSlice key_cs = dd_zend_string_to_CharSlice(key);
    switch (Z_TYPE_P(value)) {
        case IS_NULL:
            break;
        case IS_TRUE:   ddog_add_span_attr_bool_cs(target, key_cs, true); break;
        case IS_FALSE:  ddog_add_span_attr_bool_cs(target, key_cs, false); break;
        case IS_LONG:   ddog_add_span_attr_int_cs(target, key_cs, Z_LVAL_P(value)); break;
        case IS_DOUBLE: ddog_add_span_attr_double_zstr(target, key, Z_DVAL_P(value)); break;
        case IS_ARRAY:
        case IS_OBJECT:
            // An empty array stays the "" tag it was in meta (an empty List has no v0.4 form).
            if (Z_IS_RECURSIVE_P(value) || (Z_TYPE_P(value) == IS_ARRAY && !zend_hash_num_elements(Z_ARR_P(value)))) {
                ddog_add_span_attr_zstr_cs(target, key, DDOG_CHARSLICE_C(""));
            } else if (dd_native_is_list(value)) {
                ddog_span_attr_set_list(target, key_cs, dd_native_typed_build_list(value));
            } else {
                ddog_span_attr_set_map(target, key_cs, dd_native_typed_build_map(value));
            }
            break;
        default: {
            zend_string *str = dd_native_typed_str(value);
            ddog_add_span_attr_zstr_zstr(target, key, str);
            zend_string_release(str);
            break;
        }
    }
}

static void dd_serialize_array_meta_struct_recursively(ddog_SpanNode *target, zend_string *str, zval *value) {
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

    ddog_add_span_attr_bytes_zstr(target, str, (ddog_CharSlice){.ptr = data, .len = size});
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

static void dd_set_http_error(ddtrace_span_data *span, int status, bool ignore_error) {
    if (status) {
        zend_array *attributes = ddtrace_property_array(&span->property_attributes);
        zend_string *status_str = zend_long_to_str((long)status);
        zval status_zv;
        ZVAL_STR(&status_zv, status_str);
        zend_hash_str_update(attributes, ZEND_STRL("http.status_code"), &status_zv);

        // Only check status codes if not ignoring errors
        if (!ignore_error && dd_is_http_error(status) && !ddtrace_span_find_tag(span, ZEND_STRL("error.type"))) {
            zval zv;
            ZVAL_STR(&zv, zend_string_init(ZEND_STRL("HttpError"), 0));
            zend_hash_str_add_new(attributes, ZEND_STRL("error.type"), &zv);
        }
    }
}

static void dd_set_entrypoint_root_span_props_end(ddtrace_span_data *span, int status, struct iter *headers, bool ignore_error) {
    dd_set_http_error(span, status, ignore_error);

    zend_array *attributes = ddtrace_property_array(&span->property_attributes);
    for (zend_string *lowerheader, *headerval; headers->next(headers, &lowerheader, &headerval);) {
        dd_add_header_to_meta(attributes, "response", lowerheader, headerval);
        zend_string_release(lowerheader);
        zend_string_release(headerval);
    }
}

static void dd_set_entrypoint_root_rust_span_props_end(ddog_SpanNode *span, struct iter *headers) {
    for (zend_string *lowerheader, *headerval; headers->next(headers, &lowerheader, &headerval);) {
        dd_add_header_to_rust_span(span, "response", lowerheader, headerval);
        zend_string_release(lowerheader);
        zend_string_release(headerval);
    }
}

// $ignoreError, or the deprecated meta["error.ignored"] of a span not yet serialized (serialized spans
// have it folded into the property, see ddtrace_precompute_span).
static bool dd_span_ignores_error(ddtrace_span_data *span) {
    if (zend_is_true(&span->property_ignore_error)) {
        return true;
    }
    // Either array can turn it on; a falsy tag in one does not mask the other.
    zval *attr = zend_hash_str_find(ddtrace_property_array(&span->property_attributes), ZEND_STRL("error.ignored"));
    zval *meta = zend_hash_str_find(ddtrace_property_array(&span->property_meta), ZEND_STRL("error.ignored"));
    return (attr && zend_is_true(attr)) || (meta && zend_is_true(meta));
}

static bool should_track_error(zend_object *exception, ddtrace_span_data *span) {
    if (Z_TYPE(span->property_exception) != IS_OBJECT || Z_OBJ(span->property_exception) != exception) {
        return true;
    }

    zval *zv;

    // Check if error should be ignored or tracking is disabled
    if (dd_span_ignores_error(span)) {
        return false;
    }
    if ((zv = ddtrace_span_find_tag(span, ZEND_STRL("track_error"))) && !zend_is_true(zv)) {
        return false;
    }
    return true;
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

// Global tags (DD_TAGS, add_global_tag) are attribute defaults: one still at its default yields to a
// deprecated $meta value for the same key, as when the defaults lived in meta.
static void dd_yield_global_defaults_to_meta(zend_array *attributes, zend_array *meta, zend_array *globals) {
    zend_string *key;
    zval *global, *attr;
    ZEND_HASH_FOREACH_STR_KEY_VAL(globals, key, global) {
        if (key && zend_hash_exists(meta, key) && (attr = zend_hash_find(attributes, key)) && zend_is_identical(attr, global)) {
            zend_hash_del(attributes, key);
        }
    } ZEND_HASH_FOREACH_END();
}

// The tag `key` with the attributes > meta (or metrics) precedence.
static inline zval *dd_find_tag(zend_array *attributes, zend_array *fallback, const char *key, size_t len) {
    zval *zv = zend_hash_str_find(attributes, key, len);
    return zv ? zv : zend_hash_str_find(fallback, key, len);
}

static inline void dd_del_tag(zend_array *attributes, zend_array *fallback, const char *key, size_t len) {
    zend_hash_str_del(attributes, key, len);
    zend_hash_str_del(fallback, key, len);
}

ddog_SpanNode *ddtrace_serialize_span_to_rust_span(ddtrace_span_data *span, ddtrace_serialize_ctx *ctx) {
    zend_array *attributes = ddtrace_property_array(&span->property_attributes);
    zend_array *meta = ddtrace_property_array(&span->property_meta);
    zend_array *metrics = ddtrace_property_array(&span->property_metrics);

    if (zend_hash_num_elements(meta)) {
        dd_yield_global_defaults_to_meta(attributes, meta, get_DD_TAGS());
        dd_yield_global_defaults_to_meta(attributes, meta, DDTRACE_G(additional_global_tags));
    }

    // Same remaps for typed (numeric) attributes: http.status_code is a string tag on the wire.
    zval *attr_status_code = zend_hash_str_find(attributes, ZEND_STRL("http.status_code"));
    if (attr_status_code && Z_TYPE_P(attr_status_code) != IS_STRING) {
        zval status_code_as_string;
        datadog_convert_to_string(&status_code_as_string, attr_status_code);
        zend_hash_str_update(attributes, ZEND_STRL("http.status_code"), &status_code_as_string);
    }
    zval *attr_response_status_code = zend_hash_str_find(attributes, ZEND_STRL("http.response.status_code"));
    if (attr_response_status_code && Z_TYPE_P(attr_response_status_code) != IS_STRING) {
        zval status_code_as_string;
        datadog_convert_to_string(&status_code_as_string, attr_response_status_code);
        zend_hash_str_update(attributes, ZEND_STRL("http.status_code"), &status_code_as_string);
        zend_hash_str_del(attributes, ZEND_STRL("http.response.status_code"));
    }

    // Remap OTel's status code (metric, http.status_code) to DD's status code (meta, http.status_code)
    // OTel HTTP semantic conventions < 1.21.0
    zval *http_status_code = zend_hash_str_find(metrics, ZEND_STRL("http.status_code"));
    if (http_status_code) {
        zval status_code_as_string;
        datadog_convert_to_string(&status_code_as_string, http_status_code);
        zend_hash_str_update(meta, ZEND_STRL("http.status_code"), &status_code_as_string);
        zend_hash_str_del(metrics, ZEND_STRL("http.status_code"));
    }

    // Remap OTel's status code (metric, http.response.status_code) to DD's status code (meta, http.status_code)
    // OTel HTTP semantic conventions >= 1.21.0
    zval *http_response_status_code = zend_hash_str_find(metrics, ZEND_STRL("http.response.status_code"));
    if (http_response_status_code) {
        zval status_code_as_string;
        datadog_convert_to_string(&status_code_as_string, http_response_status_code);
        zend_hash_str_update(meta, ZEND_STRL("http.status_code"), &status_code_as_string);
        zend_hash_str_del(metrics, ZEND_STRL("http.response.status_code"));
    }

    ddtrace_span_precomputed pre;
    ddtrace_precompute_span(span, &pre);

    // Trace-level filter: when stats computation is enabled, drop the span from the
    // entire pipeline (trace sending + stats) if its trace is filtered.
    if (DATADOG_G(sidecar) && get_DD_TRACE_STATS_COMPUTATION_ENABLED()) {
        if (DATADOG_G(agent_info_reader)) {
            ddog_apply_agent_info_concentrator_config(DATADOG_G(agent_info_reader));
        }
        if (!ddtrace_trace_passes_filter(span)) {
            ddtrace_free_span_precomputed(&pre);
            return NULL;
        }
    }

    bool is_root_span = span->std.ce == ddtrace_ce_root_span_data;
    bool is_inferred_span = span->std.ce == ddtrace_ce_inferred_span_data;

    if (ddtrace_span_is_entrypoint_root(span) || is_inferred_span) {
        int status = SG(sapi_headers).http_response_code;
        if (datadog_active_sapi == DATADOG_PHP_SAPI_FRANKENPHP && !status) {
            status = pre.has_exception ? 500 : 200;
        }
        dd_set_http_error(span, status, pre.ignore_error);
    }

    ddtrace_span_data *inferred_span = NULL;
    if (is_root_span) {
        ddtrace_root_span_data *root_span = ROOTSPANDATA(&span->std);
        inferred_span = ddtrace_get_inferred_span(root_span);
        if (inferred_span) {
            inferred_span->root = root_span;
        }
    }

    // Notify profiling for Endpoint Profiling.
    if (profiling_notify_trace_finished && ddtrace_span_is_entrypoint_root(span) && pre.resource) {
        zai_str type = ZAI_STRL("custom");
        if (pre.type) {
            type = (zai_str) ZAI_STR_FROM_ZSTR(pre.type);
        }
        zai_str resource = (zai_str)ZAI_STR_FROM_ZSTR(pre.resource);
        LOG(DEBUG, "Notifying profiler of finished local root span.");
        profiling_notify_trace_finished(span->span_id, type, resource);
    }

    // Determine sampling before allocating the rust span to avoid unnecessary work.
    bool p0_trace = ddtrace_fetch_priority_sampling_from_span(span->root) <= 0;
    bool span_sampling_applied = false;
    double span_sampling_rate = 1.0;
    double span_sampling_max_per_second = 0.0;
    bool span_sampling_has_max = false;

    if (p0_trace && !is_inferred_span && zend_hash_num_elements(get_DD_SPAN_SAMPLING_RULES())) {
        zval *rule;
        ZEND_HASH_FOREACH_VAL(get_DD_SPAN_SAMPLING_RULES(), rule) {
            if (Z_TYPE_P(rule) != IS_ARRAY) {
                continue;
            }

            bool rule_matches = true;

            zval *rule_service;
            if ((rule_service = zend_hash_str_find(Z_ARR_P(rule), ZEND_STRL("service")))) {
                if (pre.service) {
                    rule_matches &= dd_glob_rule_matches(rule_service, pre.service);
                } else {
                    rule_matches = false;
                }
            }
            zval *rule_name;
            if ((rule_name = zend_hash_str_find(Z_ARR_P(rule), ZEND_STRL("name")))) {
                if (pre.name) {
                    rule_matches &= dd_glob_rule_matches(rule_name, pre.name);
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

            span_sampling_rate = sample_rate;
            span_sampling_has_max = max_per_second_zv != NULL;
            span_sampling_max_per_second = max_per_second;
            span_sampling_applied = true;
            break;
        }
        ZEND_HASH_FOREACH_END();
    }


    if (p0_trace && !span_sampling_applied && DATADOG_G(sidecar) && get_DD_TRACE_STATS_COMPUTATION_ENABLED() && ddog_agent_has_stats_computation()) {
        if (inferred_span) {
            // Inferred span won't be serialized, so feed it to the concentrator here.
            ddtrace_span_precomputed inferred_pre;
            ddtrace_precompute_span(inferred_span, &inferred_pre);
            ddtrace_feed_span_to_concentrator(inferred_span, &inferred_pre);
            ddtrace_free_span_precomputed(&inferred_pre);
        }
        ddtrace_feed_span_to_concentrator(span, &pre);
        ddtrace_free_span_precomputed(&pre);
        return NULL;
    }

    // The span is built directly into the native V1 builder chunk; the chunk carries the 128-bit
    // trace id (set at creation).
    if (ctx->chunk == DD_CHUNK_NONE) {
        // dropped_trace is reserved for the agent; the chunk priority carries the sampling decision.
        ctx->chunk = ddog_new_chunk(ctx->builder, span->root->trace_id.high, span->root->trace_id.low);
    }
    bool is_first_span = ddog_chunk_span_count(ctx->chunk) == 0;
    ddog_SpanNode *rspan = ddog_new_span(ctx->chunk);

    ddog_span_set_id(rspan, span->span_id);

    uint64_t parent_id_set = 0;
    bool has_parent_id = false;
    if (inferred_span) {
        parent_id_set = inferred_span->span_id; has_parent_id = true;
    } else if (span->parent) { // handle dropped spans
        ddtrace_span_data *parent = SPANDATA(span->parent);
        // Ensure the parent id is the root span if everything else was dropped
        while (parent->parent && ddtrace_span_is_dropped(parent)) {
            parent = SPANDATA(parent->parent);
        }
        if (parent) {
            parent_id_set = parent->span_id; has_parent_id = true;
        }
    } else if (is_root_span) {
        parent_id_set = ROOTSPANDATA(&span->std)->parent_id; has_parent_id = true;
    } else if (is_inferred_span) {
        parent_id_set = span->root->parent_id; has_parent_id = true;
    }
    if (has_parent_id) {
        ddog_span_set_parent_id(rspan, parent_id_set);
    }

    ddog_span_set_start(rspan, span->start);
    ddog_span_set_duration(rspan, span->duration);

    if (is_first_span) {
        zend_string *process_tags = datadog_process_tags_get_serialized();
        if (ZSTR_LEN(process_tags)) {
            const char *svc_tag_appendix = NULL;
            const char *normalized_default = NULL;
            zend_string *dd_service = get_DD_SERVICE();

            if (dd_service && ZSTR_LEN(dd_service)) {
                svc_tag_appendix = ",svc.user:true";
            } else {
                if (!ddtrace_span_find_tag(&span->root->span, ZEND_STRL("_dd.svc_src"))) {
                    zend_string *root_svc = datadog_convert_to_str(&span->root->property_service);
                    if (ZSTR_LEN(root_svc)) {
                        normalized_default = ddog_normalize_process_tag_value(dd_zend_string_to_CharSlice(root_svc));
                    }
                    zend_string_release(root_svc);
                }
            }

            if (svc_tag_appendix || normalized_default) {
                smart_str combined = {0};
                smart_str_append(&combined, process_tags);
                if (svc_tag_appendix) {
                    smart_str_appends(&combined, svc_tag_appendix);
                } else {
                    smart_str_appends(&combined, ",svc.auto:");
                    smart_str_appends(&combined, normalized_default);
                }
                smart_str_0(&combined);
                dd_span_attr_zstr(rspan, "_dd.tags.process", combined.s);
                smart_str_free(&combined);
            } else {
                dd_span_attr_zstr(rspan, "_dd.tags.process", process_tags);
            }

            if (normalized_default) {
                ddog_free_normalized_tag_value(normalized_default);
            }
        }
    }

    // SpanData::$name defaults to fully qualified called name (set at span close)
    if (pre.name) {
        ddog_set_span_name_zstr(rspan, pre.name);
    }
    if (pre.name_from_meta) {
        dd_del_tag(attributes, meta, ZEND_STRL("operation.name"));
    }

    // SpanData::$resource defaults to SpanData::$name
    if (pre.resource) {
        ddog_set_span_resource_zstr(rspan, pre.resource);
    }
    if (pre.resource_from_meta) {
        dd_del_tag(attributes, meta, ZEND_STRL("resource.name"));
    }

    // TODO: SpanData::$service defaults to parent SpanData::$service or DD_SERVICE if root span
    if (pre.service) {
        ddog_set_span_service_zstr(rspan, pre.service);
    }
    if (pre.service_from_meta) {
        dd_del_tag(attributes, meta, ZEND_STRL("service.name"));
    }

    // SpanData::$type is optional and defaults to 'custom' at the Agent level
    if (pre.type) {
        ddog_set_span_type_zstr(rspan, pre.type);
    }
    if (pre.type_from_meta) {
        dd_del_tag(attributes, meta, ZEND_STRL("span.type"));
    }

    dd_del_tag(attributes, meta, ZEND_STRL("analytics.event"));

    if (span_sampling_applied) {
        ddog_add_span_attr_double_lit(rspan, "_dd.span_sampling.mechanism", 8.0);
        ddog_add_span_attr_double_lit(rspan, "_dd.span_sampling.rule_rate", span_sampling_rate);
        if (span_sampling_has_max) {
            ddog_add_span_attr_double_lit(rspan, "_dd.span_sampling.max_per_second", span_sampling_max_per_second);
        }
    }

    // Promote span/chunk fields up front (their tags are deleted below). env/version are property-first
    // with a tag fallback: DD_TAGS "env"/"version" land in meta when DD_ENV/DD_VERSION are unset.
    // pre.env/pre.version already carry that fallback.
    if (pre.env) {
        ddog_set_span_env(rspan, dd_zend_string_to_CharSlice(pre.env));
    }
    if (pre.version) {
        ddog_set_span_version(rspan, dd_zend_string_to_CharSlice(pre.version));
    }

    zval *component_prop = &span->property_component;
    ZVAL_DEREF(component_prop);
    if (Z_TYPE_P(component_prop) == IS_STRING && Z_STRLEN_P(component_prop) > 0) {
        ddog_set_span_component(rspan, dd_zend_string_to_CharSlice(Z_STR_P(component_prop)));
    } else {
        zval *component_meta = dd_find_tag(attributes, meta, ZEND_STRL("component"));
        if (component_meta && Z_TYPE_P(component_meta) == IS_STRING) {
            ddog_set_span_component(rspan, dd_zend_string_to_CharSlice(Z_STR_P(component_meta)));
        }
    }

    zval *span_kind_prop = &span->property_span_kind;
    ZVAL_DEREF(span_kind_prop);
    // Non-canonical span.kind strings (e.g. "process") collapse to Internal with no way back;
    // keep them as a plain attribute instead of deleting below.
    bool span_kind_is_canonical = true;
    if (Z_TYPE_P(span_kind_prop) == IS_LONG && Z_LVAL_P(span_kind_prop) >= 1 && Z_LVAL_P(span_kind_prop) <= 5) {
        ddog_set_span_kind(rspan, (uint32_t)Z_LVAL_P(span_kind_prop));
    } else {
        zval *span_kind_meta = dd_find_tag(attributes, meta, ZEND_STRL("span.kind"));
        if (span_kind_meta && Z_TYPE_P(span_kind_meta) == IS_STRING) {
            span_kind_is_canonical = ddog_set_span_kind_str(rspan, dd_zend_string_to_CharSlice(Z_STR_P(span_kind_meta)));
        }
    }

    // _dd.origin is property-sourced; the chunk carries it (never a span attribute).
    zval *origin = &span->root->property_origin;
    if (Z_TYPE_P(origin) > IS_NULL && (Z_TYPE_P(origin) != IS_STRING || Z_STRLEN_P(origin))) {
        ddog_set_chunk_origin(ctx->chunk, dd_zend_string_to_CharSlice(Z_STR_P(origin)));
    }

    {
        // _dd.p.dm v0.4 form is "-N"; the mechanism is the trailing unsigned integer -> chunk field.
        zval *dm_meta = dd_find_tag(attributes, meta, ZEND_STRL("_dd.p.dm"));
        if (dm_meta && Z_TYPE_P(dm_meta) == IS_STRING) {
            const char *p = Z_STRVAL_P(dm_meta);
            size_t n = Z_STRLEN_P(dm_meta);
            if (n && *p == '-') { p++; n--; }
            uint32_t mech = 0;
            for (size_t i = 0; i < n; i++) { if (p[i] < '0' || p[i] > '9') { mech = 0; break; } mech = mech * 10 + (uint32_t)(p[i] - '0'); }
            ddog_set_chunk_sampling_mechanism(ctx->chunk, mech);
        }
        // Delete promoted keys so the copy loops only carry plain attributes.
        dd_del_tag(attributes, meta, ZEND_STRL("env"));
        dd_del_tag(attributes, meta, ZEND_STRL("version"));
        dd_del_tag(attributes, meta, ZEND_STRL("component"));
        if (span_kind_is_canonical) {
            dd_del_tag(attributes, meta, ZEND_STRL("span.kind"));
        }
        dd_del_tag(attributes, meta, ZEND_STRL("_dd.origin"));
        dd_del_tag(attributes, meta, ZEND_STRL("_dd.p.dm"));
        // _sampling_priority_v1 becomes the chunk sampling priority below; _dd1.sr.eausr is not emitted.
        dd_del_tag(attributes, metrics, ZEND_STRL("_dd1.sr.eausr"));
        dd_del_tag(attributes, metrics, ZEND_STRL("_sampling_priority_v1"));
    }

    // Precedence attributes > meta > metrics: each loop skips keys an earlier one already set.
    zend_string *attr_str_key;
    zval *attr_val;
    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(attributes, attr_str_key, attr_val) {
        if (attr_str_key && !ddog_has_span_attr_zstr(rspan, attr_str_key)) {
            dd_serialize_typed_attribute(rspan, attr_str_key, attr_val);
        }
    } ZEND_HASH_FOREACH_END();

    if (zend_hash_num_elements(meta)) {
        zend_string *meta_str_key;
        zval *orig_val;
        ZEND_HASH_FOREACH_STR_KEY_VAL_IND(meta, meta_str_key, orig_val) {
            if (meta_str_key) {
                if (!ddog_has_span_attr_zstr(rspan, meta_str_key)) {
                    dd_serialize_array_meta_recursively(rspan, meta_str_key, orig_val);
                }
            }
        }
        ZEND_HASH_FOREACH_END();
    }

    zval *exception_zv = &span->property_exception;
    if (pre.has_exception && !pre.ignore_error) {
        enum dd_exception exception_type = DD_EXCEPTION_THROWN;
        if (is_root_span) {
            exception_type = Z_PROP_FLAG_P(exception_zv) == 2 ? DD_EXCEPTION_CAUGHT : DD_EXCEPTION_UNCAUGHT;
        }
        ddtrace_exception_to_meta(Z_OBJ_P(exception_zv), pre.service ? pre.service : ZSTR_EMPTY_ALLOC(), span->start, rspan, exception_type);
    }

    // Links/events are emitted natively from the PHP span (the _dd.span_links/events meta keys are
    // never produced on the V1 path).
    zend_array *span_links = ddtrace_property_array(&span->property_links);
    if (zend_hash_num_elements(span_links) > 0) {
        dd_span_links_to_rust(span_links, rspan);
    }

    zend_array *span_events = ddtrace_property_array(&span->property_events);
    if (zend_hash_num_elements(span_events) > 0) {
        dd_span_events_to_rust(span_events, rspan);
    }

    zval *git_metadata = &span->root->property_git_metadata;
    if (git_metadata && Z_TYPE_P(git_metadata) == IS_OBJECT) {
        datadog_git_metadata *metadata = (datadog_git_metadata *)Z_OBJ_P(git_metadata);
        if (is_root_span) {
            if (Z_TYPE(metadata->property_commit) == IS_STRING) {
                zend_string *commit_sha = datadog_convert_to_str(&metadata->property_commit);
                dd_span_attr_zstr(rspan, "_dd.git.commit.sha", commit_sha);
                zend_string_release(commit_sha);
            }
            if (Z_TYPE(metadata->property_repository) == IS_STRING) {
                zend_string *repository_url = datadog_convert_to_str(&metadata->property_repository);
                dd_span_attr_zstr(rspan, "_dd.git.repository_url", repository_url);
                zend_string_release(repository_url);
            }
        }
    }

    if (get_DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED()) {
        zend_array *peer_service_sources = ddtrace_property_array(&span->property_peer_service_sources);
        zval *peer_service_tag = dd_find_tag(attributes, meta, ZEND_STRL("peer.service"));
        if (peer_service_tag && Z_TYPE_P(peer_service_tag) == IS_STRING) {
            dd_span_attr_str(rspan, "_dd.peer.service.source", "peer.service");
            dd_set_mapped_peer_service(rspan, Z_STR_P(peer_service_tag));
        } else if (zend_hash_num_elements(peer_service_sources) > 0) {
            zval *tag;
            ZEND_HASH_FOREACH_VAL(peer_service_sources, tag) {
                if (Z_TYPE_P(tag) == IS_STRING) {
                    zval *found_peer_service = zend_hash_find(attributes, Z_STR_P(tag));
                    if (!found_peer_service) {
                        found_peer_service = zend_hash_find(meta, Z_STR_P(tag));
                    }
                    if (found_peer_service && Z_TYPE_P(found_peer_service) == IS_STRING) {
                        dd_span_attr_zstr(rspan, "_dd.peer.service.source", Z_STR_P(tag));
                        zend_string *peer = zval_get_string(found_peer_service);
                        if (!dd_set_mapped_peer_service(rspan, peer)) {
                            dd_span_attr_zstr(rspan, "peer.service", peer);
                        }
                        zend_string_release(peer);
                        break;
                    }
                }
            } ZEND_HASH_FOREACH_END();
        }
    }

    if (ddtrace_span_is_entrypoint_root(span) || is_inferred_span) {
        struct iter *headers = dd_iterate_sapi_headers();
        dd_set_entrypoint_root_rust_span_props_end(rspan, headers);
        efree(headers);
    }

    bool error = dd_compute_span_is_error(&pre);
    if (error) {
        ddog_span_set_error(rspan, true);
        if (Z_TYPE(span->property_exception) == IS_OBJECT) {
            zend_object *exception = Z_OBJ(span->property_exception);
            ddtrace_span_data *current = span;
            bool should_track;
            do {
                should_track = should_track_error(exception, current);
                if (!should_track) {
                    dd_span_attr_str(rspan, "track_error", "false");
                    break;
                }
                current = current->parent ? SPANDATA(current->parent) : NULL;
            } while (current);
        }
    }

    // Add _dd.base_service if service name differs from mapped root service name.
    zval prop_service_as_string;
    datadog_convert_to_string(&prop_service_as_string, &span->property_service);
    zval prop_root_service_as_string;
    datadog_convert_to_string(&prop_root_service_as_string, &span->root->property_service);
    zval *new_root_name = zend_hash_find(get_DD_SERVICE_MAPPING(), Z_STR(prop_root_service_as_string));
    if (new_root_name) {
        zend_string_release(Z_STR(prop_root_service_as_string));
        ZVAL_COPY(&prop_root_service_as_string, new_root_name);
    }
    if (!is_inferred_span && !zend_string_equals_ci(Z_STR(prop_service_as_string), Z_STR(prop_root_service_as_string))) {
        dd_span_attr_zstr(rspan, "_dd.base_service", Z_STR_P(&prop_root_service_as_string));
    }
    zend_string_release(Z_STR(prop_root_service_as_string));
    zend_string_release(Z_STR(prop_service_as_string));

    if (DATADOG_G(sidecar) && get_DD_TRACE_STATS_COMPUTATION_ENABLED() && ddog_agent_has_stats_computation()) {
        ddtrace_feed_span_to_concentrator(span, &pre);
    }

    if (zend_hash_num_elements(metrics)) {
        zend_string *str_key;
        zval *val;
        ZEND_HASH_FOREACH_STR_KEY_VAL_IND(metrics, str_key, val) {
            if (str_key) {
                if (!ddog_has_span_attr_zstr(rspan, str_key)) {
                    dd_serialize_array_metrics_recursively(rspan, str_key, val);
                }
            }
        } ZEND_HASH_FOREACH_END();
    }

    // _sampling_priority_v1 is a chunk-level field, not a span metric.
    if ((is_root_span && !inferred_span) || is_inferred_span) {
        if (Z_TYPE_P(&span->root->property_sampling_priority) != IS_UNDEF) {
            long sampling_priority = zval_get_long(&span->root->property_sampling_priority);
            if (!get_global_DD_APM_TRACING_ENABLED() && !ddtrace_trace_source_is_asm_sourced(attributes, meta)) {
                sampling_priority = MIN(PRIORITY_SAMPLING_AUTO_KEEP, sampling_priority);
            }
            ddog_set_chunk_sampling_priority(ctx->chunk, (int32_t)sampling_priority);
        }
    }

    if (!get_global_DD_APM_TRACING_ENABLED()) {
        ddog_add_span_attr_double_lit(rspan, "_dd.apm.enabled", 0);
    }

    if (DATADOG_G(sidecar) && get_DD_TRACE_STATS_COMPUTATION_ENABLED() && !is_inferred_span) {
        bool is_top_level_span = !span->parent;
        if (span->parent) {
            zval *parent_service = &SPANDATA(span->parent)->property_service;
            zval *span_service = &span->property_service;
            ZVAL_DEREF(parent_service);
            ZVAL_DEREF(span_service);
            if (Z_TYPE_P(span_service) == IS_STRING && Z_TYPE_P(parent_service) == IS_STRING) {
                is_top_level_span = !zend_string_equals(Z_STR_P(span_service), Z_STR_P(parent_service));
            } else if (Z_TYPE_P(span_service) != Z_TYPE_P(parent_service)) {
                is_top_level_span = true;
            }
        }
        if (is_top_level_span) {
            ddog_add_span_attr_double_lit(rspan, "_dd.top_level", 1);
        }
    }

    if (ddtrace_span_is_entrypoint_root(span)) {
        if (get_DD_TRACE_MEASURE_COMPILE_TIME()) {
            ddog_add_span_attr_double_lit(rspan, "php.compilation.total_time_ms", ddtrace_compile_time_get() / 1000.);
        }
        if (get_DD_TRACE_MEASURE_PEAK_MEMORY_USAGE()) {
            ddog_add_span_attr_double_lit(rspan, "php.memory.peak_usage_bytes", zend_memory_peak_usage(false));
            ddog_add_span_attr_double_lit(rspan, "php.memory.peak_real_usage_bytes", zend_memory_peak_usage(true));
        }
    }

    ddog_SpanNode *inferred = inferred_span ? ddtrace_serialize_span_to_rust_span(inferred_span, ctx) : NULL;
    // A dropped inferred span returns NULL; skip the transfers then. Node pointers stay valid across
    // sibling pushes, so rspan is still usable after the inferred span was added to the chunk.
    if (inferred) {
        ddog_transfer_span_attr(rspan, inferred, "_dd.agent_psr", true);
        ddog_transfer_span_attr(rspan, inferred, "_dd.rule_psr", true);
        ddog_transfer_span_attr(rspan, inferred, "_dd.limit_psr", true);

        ddog_transfer_span_attr(rspan, inferred, "error.message", false);
        ddog_transfer_span_attr(rspan, inferred, "error.type", false);
        ddog_transfer_span_attr(rspan, inferred, "error.stack", false);
        ddog_transfer_span_attr(rspan, inferred, "track_error", false);
        ddog_transfer_span_attr(rspan, inferred, "_dd.p.dm", true);
        ddog_transfer_span_attr(rspan, inferred, "_dd.p.ksr", false);
        ddog_transfer_span_attr(rspan, inferred, "_dd.svc_src", false);
        ddog_transfer_span_attr(rspan, inferred, DD_TAG_HTTP_REQH_ENDPOINT_SCAN, false);
        ddog_transfer_span_attr(rspan, inferred, DD_TAG_HTTP_REQH_SECURITY_TEST, false);

        ddog_span_set_error(inferred, ddog_span_get_error(rspan));
    }

    LOGEV(SPAN, {
        ddog_CharSlice span_log = ddog_v1_span_debug_log(ctx->chunk, rspan);
        log("Encoding span: %s", span_log.ptr);
        ddog_free_charslice(span_log);
    });

    zend_array *meta_struct = ddtrace_property_array(&span->property_meta_struct);
    zend_string *ms_str_key;
    zval *ms_val;
    ZEND_HASH_FOREACH_STR_KEY_VAL_IND(meta_struct, ms_str_key, ms_val) {
        if (ms_str_key) {
            dd_serialize_array_meta_struct_recursively(rspan, ms_str_key, ms_val);
        }
    }
    ZEND_HASH_FOREACH_END();

    ddtrace_free_span_precomputed(&pre);
    return rspan;
}

// Growable index path into a span's nested attribute tree (introspection read-back only).
typedef struct { uintptr_t *data; size_t len; size_t cap; } dd_attr_path;

static void dd_attr_path_push(dd_attr_path *p, uintptr_t idx) {
    if (p->len == p->cap) {
        p->cap = p->cap ? p->cap * 2 : 8;
        p->data = erealloc(p->data, p->cap * sizeof(uintptr_t));
    }
    p->data[p->len++] = idx;
}

// Reads the native V1 attribute at `path` (under the span, or one of its links/events selected by
// `kind`/`idx`) into a zval, recursing into List/KeyValue so nested attributes surface as nested
// PHP arrays (a list becomes a packed array, a KeyValue an assoc map).
static void dd_node_attr_value_to_zval(ddog_TracerPayloadV1Builder *b, uintptr_t c, uintptr_t sp,
                                       uint32_t kind, uintptr_t idx, dd_attr_path *path, zval *out) {
    switch (ddog_v1_get_node_attr_child_type(b, c, sp, kind, idx, path->data, path->len)) {
        case ddog_DDOG_V1_ATTR_INT:
            ZVAL_LONG(out, ddog_v1_get_node_attr_child_int(b, c, sp, kind, idx, path->data, path->len));
            break;
        case ddog_DDOG_V1_ATTR_DOUBLE:
            ZVAL_DOUBLE(out, ddog_v1_get_node_attr_child_double(b, c, sp, kind, idx, path->data, path->len));
            break;
        case ddog_DDOG_V1_ATTR_BOOL:
            ZVAL_BOOL(out, ddog_v1_get_node_attr_child_bool(b, c, sp, kind, idx, path->data, path->len));
            break;
        case ddog_DDOG_V1_ATTR_BYTES:
            ZVAL_STR(out, dd_CharSlice_to_zend_string(ddog_v1_get_node_attr_child_bytes(b, c, sp, kind, idx, path->data, path->len)));
            break;
        case ddog_DDOG_V1_ATTR_LIST: {
            array_init(out);
            size_t n = ddog_v1_get_node_attr_child_count(b, c, sp, kind, idx, path->data, path->len);
            for (size_t i = 0; i < n; i++) {
                dd_attr_path_push(path, i);
                zval v;
                dd_node_attr_value_to_zval(b, c, sp, kind, idx, path, &v);
                add_next_index_zval(out, &v);
                path->len--;
            }
            break;
        }
        case ddog_DDOG_V1_ATTR_KEYVALUE: {
            array_init(out);
            size_t n = ddog_v1_get_node_attr_child_count(b, c, sp, kind, idx, path->data, path->len);
            for (size_t i = 0; i < n; i++) {
                dd_attr_path_push(path, i);
                ddog_CharSlice mkey = ddog_v1_get_node_attr_child_key(b, c, sp, kind, idx, path->data, path->len);
                zval v;
                dd_node_attr_value_to_zval(b, c, sp, kind, idx, path, &v);
                add_assoc_zval_ex(out, mkey.ptr, mkey.len, &v);
                path->len--;
            }
            break;
        }
        default: // STRING
            ZVAL_STR(out, dd_CharSlice_to_zend_string(ddog_v1_get_node_attr_child_str(b, c, sp, kind, idx, path->data, path->len)));
            break;
    }
}

// Introspection reader for the native V1 builder: promoted and chunk-level fields are surfaced
// directly, the unified typed attribute map under "attributes", and links/events natively.
zval dd_serialize_rust_to_zval(ddog_TracerPayloadV1Builder *b) {
    zval traces_zv;
    array_init(&traces_zv);

    for (size_t c = 0; c < ddog_v1_get_chunk_count(b); c++) {
        zval trace_zv;
        array_init(&trace_zv);

        uint64_t tid_high = ddog_v1_get_chunk_trace_id_high(b, c);
        uint64_t tid_low = ddog_v1_get_chunk_trace_id_low(b, c);
        // Chunk-level fields go on the chunk's local root only, the same span the v0.4 wire picks.
        size_t root_idx = ddog_v1_get_chunk_root_span_idx(b, c);
        int32_t chunk_priority;
        bool has_priority = ddog_v1_get_chunk_sampling_priority(b, c, &chunk_priority);
        uint32_t chunk_mechanism;
        bool has_mechanism = ddog_v1_get_chunk_sampling_mechanism(b, c, &chunk_mechanism);
        ddog_CharSlice chunk_origin = ddog_v1_get_chunk_origin(b, c);

        for (size_t j = 0; j < ddog_v1_get_span_count(b, c); j++) {
            zval span_zv;
            array_init(&span_zv);

            add_assoc_str(&span_zv, KEY_TRACE_ID, ddtrace_span_id_as_string(tid_low));
            if (tid_high && j == root_idx) {
                add_assoc_str(&span_zv, "trace_id_high", ddtrace_span_id_as_hex_string(tid_high));
            }
            add_assoc_str(&span_zv, KEY_SPAN_ID, ddtrace_span_id_as_string(ddog_v1_get_span_id(b, c, j)));
            uint64_t parent_id = ddog_v1_get_span_parent_id(b, c, j);
            if (parent_id) {
                add_assoc_str(&span_zv, KEY_PARENT_ID, ddtrace_span_id_as_string(parent_id));
            }
            add_assoc_long(&span_zv, "start", ddog_v1_get_span_start(b, c, j));
            add_assoc_long(&span_zv, "duration", ddog_v1_get_span_duration(b, c, j));
            add_assoc_str(&span_zv, "name", dd_CharSlice_to_zend_string(ddog_v1_get_span_name(b, c, j)));
            add_assoc_str(&span_zv, "resource", dd_CharSlice_to_zend_string(ddog_v1_get_span_resource(b, c, j)));
            add_assoc_str(&span_zv, "service", dd_CharSlice_to_zend_string(ddog_v1_get_span_service(b, c, j)));
            add_assoc_str(&span_zv, "type", dd_CharSlice_to_zend_string(ddog_v1_get_span_type(b, c, j)));
            if (ddog_v1_get_span_error(b, c, j)) {
                add_assoc_long(&span_zv, "error", 1);
            }

#define DD_ZVAL_PROMOTED(field, getter)                                                    \
    do {                                                                                      \
        ddog_CharSlice _v = getter(b, c, j);                                                  \
        if (_v.len) add_assoc_str(&span_zv, field, dd_CharSlice_to_zend_string(_v));          \
    } while (0)
            DD_ZVAL_PROMOTED("env", ddog_v1_get_span_env);
            DD_ZVAL_PROMOTED("version", ddog_v1_get_span_version);
            DD_ZVAL_PROMOTED("component", ddog_v1_get_span_component);
#undef DD_ZVAL_PROMOTED
            uint32_t span_kind = ddog_v1_get_span_kind(b, c, j);
            if (span_kind) {
                add_assoc_long(&span_zv, "span_kind", span_kind);
            }
            if (has_priority && j == root_idx) {
                add_assoc_long(&span_zv, "sampling_priority", chunk_priority);
            }
            if (has_mechanism && j == root_idx) {
                add_assoc_long(&span_zv, "sampling_mechanism", chunk_mechanism);
            }
            if (chunk_origin.len && j == root_idx) {
                add_assoc_str(&span_zv, "origin", dd_CharSlice_to_zend_string(chunk_origin));
            }

            size_t attr_count = ddog_v1_get_span_attr_count(b, c, j);
            if (attr_count > 0) {
                zval attrs_zv, meta_struct_zv;
                array_init(&attrs_zv);
                array_init(&meta_struct_zv);
                dd_attr_path path = {0};
                for (size_t k = 0; k < attr_count; k++) {
                    ddog_CharSlice key = ddog_v1_get_span_attr_key(b, c, j, k);
                    zval value_zv;
                    path.len = 0;
                    dd_attr_path_push(&path, k);
                    dd_node_attr_value_to_zval(b, c, j, ddog_DDOG_V1_ATTR_NODE_SPAN, 0, &path, &value_zv);
                    // Bytes-typed attributes are v0.4 meta_struct entries; surface them under
                    // "meta_struct" (as the v0.4 reader did), not mixed into the attribute map.
                    if (ddog_v1_get_span_attr_type(b, c, j, k) == ddog_DDOG_V1_ATTR_BYTES) {
                        zend_hash_str_update(Z_ARR(meta_struct_zv), key.ptr, key.len, &value_zv);
                    } else {
                        zend_hash_str_update(Z_ARR(attrs_zv), key.ptr, key.len, &value_zv);
                    }
                }
                if (path.data) {
                    efree(path.data);
                }
                if (zend_hash_num_elements(Z_ARR(attrs_zv))) {
                    add_assoc_zval(&span_zv, "attributes", &attrs_zv);
                } else {
                    zval_ptr_dtor(&attrs_zv);
                }
                if (zend_hash_num_elements(Z_ARR(meta_struct_zv))) {
                    add_assoc_zval(&span_zv, "meta_struct", &meta_struct_zv);
                } else {
                    zval_ptr_dtor(&meta_struct_zv);
                }
            }

            size_t link_count = ddog_v1_get_link_count(b, c, j);
            if (link_count > 0) {
                zval links_zv;
                array_init(&links_zv);
                for (size_t l = 0; l < link_count; l++) {
                    zval link_zv;
                    array_init(&link_zv);
                    add_assoc_str(&link_zv, KEY_TRACE_ID, ddtrace_span_id_as_string(ddog_v1_get_link_trace_id_low(b, c, j, l)));
                    uint64_t link_tid_high = ddog_v1_get_link_trace_id_high(b, c, j, l);
                    if (link_tid_high) {
                        add_assoc_str(&link_zv, "trace_id_high", ddtrace_span_id_as_hex_string(link_tid_high));
                    }
                    add_assoc_str(&link_zv, KEY_SPAN_ID, ddtrace_span_id_as_string(ddog_v1_get_link_span_id(b, c, j, l)));
                    ddog_CharSlice tracestate = ddog_v1_get_link_tracestate(b, c, j, l);
                    if (tracestate.len) {
                        add_assoc_str(&link_zv, "trace_state", dd_CharSlice_to_zend_string(tracestate));
                    }
                    add_assoc_long(&link_zv, "flags", ddog_v1_get_link_flags(b, c, j, l));
                    size_t lattr_count = ddog_v1_get_link_attr_count(b, c, j, l);
                    if (lattr_count > 0) {
                        zval lattrs_zv;
                        array_init(&lattrs_zv);
                        dd_attr_path lpath = {0};
                        for (size_t k = 0; k < lattr_count; k++) {
                            lpath.len = 0;
                            dd_attr_path_push(&lpath, k);
                            ddog_CharSlice key = ddog_v1_get_node_attr_child_key(b, c, j, ddog_DDOG_V1_ATTR_NODE_LINK, l, lpath.data, lpath.len);
                            zval v;
                            dd_node_attr_value_to_zval(b, c, j, ddog_DDOG_V1_ATTR_NODE_LINK, l, &lpath, &v);
                            zend_hash_str_update(Z_ARR(lattrs_zv), key.ptr, key.len, &v);
                        }
                        if (lpath.data) {
                            efree(lpath.data);
                        }
                        add_assoc_zval(&link_zv, "attributes", &lattrs_zv);
                    }
                    zend_hash_next_index_insert_new(Z_ARR(links_zv), &link_zv);
                }
                add_assoc_zval(&span_zv, "span_links", &links_zv);
            }

            size_t event_count = ddog_v1_get_event_count(b, c, j);
            if (event_count > 0) {
                zval events_zv;
                array_init(&events_zv);
                for (size_t e = 0; e < event_count; e++) {
                    zval event_zv;
                    array_init(&event_zv);
                    add_assoc_str(&event_zv, "name", dd_CharSlice_to_zend_string(ddog_v1_get_event_name(b, c, j, e)));
                    add_assoc_long(&event_zv, "time_unix_nano", ddog_v1_get_event_time(b, c, j, e));
                    size_t eattr_count = ddog_v1_get_event_attr_count(b, c, j, e);
                    if (eattr_count > 0) {
                        zval eattrs_zv;
                        array_init(&eattrs_zv);
                        dd_attr_path epath = {0};
                        for (size_t k = 0; k < eattr_count; k++) {
                            epath.len = 0;
                            dd_attr_path_push(&epath, k);
                            ddog_CharSlice key = ddog_v1_get_node_attr_child_key(b, c, j, ddog_DDOG_V1_ATTR_NODE_EVENT, e, epath.data, epath.len);
                            zval v;
                            dd_node_attr_value_to_zval(b, c, j, ddog_DDOG_V1_ATTR_NODE_EVENT, e, &epath, &v);
                            zend_hash_str_update(Z_ARR(eattrs_zv), key.ptr, key.len, &v);
                        }
                        if (epath.data) {
                            efree(epath.data);
                        }
                        add_assoc_zval(&event_zv, "attributes", &eattrs_zv);
                    }
                    zend_hash_next_index_insert_new(Z_ARR(events_zv), &event_zv);
                }
                add_assoc_zval(&span_zv, "span_events", &events_zv);
            }

            zend_hash_next_index_insert_new(Z_ARR_P(&trace_zv), &span_zv);
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

        dd_fatal_error_to_meta(ddtrace_property_array(&pspan->property_attributes), error);
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
    if (zai_sandbox_active && !zai_sandbox_timed_out()) {
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

                dd_fatal_error_to_meta(ddtrace_property_array(&pspan->property_attributes), error);
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
    struct iter *iter = dd_iterate_arr_arr_headers(headers);
    dd_set_entrypoint_root_span_props_end(&root_span_data->span, status, iter, false);
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

