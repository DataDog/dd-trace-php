// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog (https://www.datadoghq.com/).
// Copyright 2021 Datadog, Inc.
#include "ddappsec.h"
#include "ddtrace.h"
#include "logging.h"
#include "php_compat.h"
#include "php_helpers.h"
#include "php_objects.h"
#include "string_helpers.h"
#include "tags.h"
#include <SAPI.h>
#include <zend_smart_str.h>

#define DD_TAG_DATA "_dd.appsec.json"
#define DD_TAG_EVENT "appsec.event"
#define DD_TAG_RUNTIME_FAMILY "_dd.runtime_family"
#define DD_TAG_HTTP_METHOD "http.method"
#define DD_TAG_HTTP_USER_AGENT "http.useragent"
#define DD_TAG_HTTP_STATUS_CODE "http.status_code"
#define DD_TAG_HTTP_URL "http.url"
#define DD_TAG_NETWORK_CLIENT_IP "network.client.ip"
#define DD_PREFIX_TAG_REQUEST_HEADER "http.request.headers."
#define DD_PREFIX_TAG_RESPONSE_HEADER "http.response.headers."
#define DD_METRIC_ENABLED "_dd.appsec.enabled"
#define DD_METRIC_SAMPLING_PRIORITY "_sampling_priority_v1"
#define DD_SAMPLING_PRIORITY_USER_KEEP 2

static zend_string *_dd_tag_data_zstr;
static zend_string *_dd_tag_event_zstr;
static zend_string *_dd_tag_http_method_zstr;
static zend_string *_dd_tag_http_user_agent_zstr;
static zend_string *_dd_tag_http_status_code_zstr;
static zend_string *_dd_tag_http_url_zstr;
static zend_string *_dd_tag_network_client_ip_zstr;
static zend_string *_dd_metric_enabled;
static zend_string *_dd_metric_sampling_prio_zstr;
static zend_string *_key_request_uri_zstr;
static zend_string *_key_http_host_zstr;
static zend_string *_key_server_name_zstr;
static zend_string *_key_http_user_agent_zstr;
static zend_string *_key_https_zstr;
static zend_string *_key_remote_addr_zstr;
static zval _true_zv;
static HashTable _relevant_headers;
static THREAD_LOCAL_ON_ZTS bool _appsec_json_frags_inited;
static THREAD_LOCAL_ON_ZTS zend_llist _appsec_json_frags;

static void _init_relevant_headers(void);
static zend_string* _concat_json_fragments(void);
static void _zend_string_release_indirect(void *s);
static bool _add_ancillary_tags(void);
void _set_runtime_family(void);
static bool _set_appsec_enabled(zval *metrics_zv);
static void _set_sampling_priority(zval *metrics_zv);
static void _register_test_functions(void);

void dd_tags_startup()
{
    _dd_tag_data_zstr =
        zend_string_init_interned(LSTRARG(DD_TAG_DATA), 1 /* permanent */);
    _dd_tag_event_zstr =
        zend_string_init_interned(LSTRARG(DD_TAG_EVENT), 1 /* permanent */);
    zend_string *true_zstr =
        zend_string_init_interned(LSTRARG("true"), 1 /* permanent */);
    ZVAL_STR(&_true_zv, true_zstr);

    _dd_tag_http_method_zstr =
        zend_string_init_interned(LSTRARG(DD_TAG_HTTP_METHOD), 1);
    _dd_tag_http_user_agent_zstr =
        zend_string_init_interned(LSTRARG(DD_TAG_HTTP_USER_AGENT), 1);
    _dd_tag_http_status_code_zstr =
        zend_string_init_interned(LSTRARG(DD_TAG_HTTP_STATUS_CODE), 1);
    _dd_tag_http_url_zstr =
        zend_string_init_interned(LSTRARG(DD_TAG_HTTP_URL), 1);
    _dd_tag_network_client_ip_zstr =
        zend_string_init_interned(LSTRARG(DD_TAG_NETWORK_CLIENT_IP), 1);

    _dd_metric_enabled =
        zend_string_init_interned(LSTRARG(DD_METRIC_ENABLED), 1);
    _dd_metric_sampling_prio_zstr =
        zend_string_init_interned(LSTRARG(DD_METRIC_SAMPLING_PRIORITY), 1);

    _key_request_uri_zstr =
        zend_string_init_interned(LSTRARG("REQUEST_URI"), 1);
    _key_http_host_zstr =
        zend_string_init_interned(LSTRARG("HTTP_HOST"), 1);
    _key_server_name_zstr =
        zend_string_init_interned(LSTRARG("SERVER_NAME"), 1);
    _key_http_user_agent_zstr =
        zend_string_init_interned(LSTRARG("HTTP_USER_AGENT"), 1);
    _key_https_zstr = zend_string_init_interned(LSTRARG("HTTPS"), 1);
    _key_remote_addr_zstr =
        zend_string_init_interned(LSTRARG("REMOTE_ADDR"), 1);

    _init_relevant_headers();
    
    if (DDAPPSEC_G(testing)) {
        _register_test_functions();
    }
}
static void _init_relevant_headers()
{
    zend_hash_init(&_relevant_headers, 32, NULL, NULL, 1);
    zval nullzv;
    ZVAL_NULL(&nullzv);
#define ADD_RELEVANT_HEADER(str)                                               \
    zend_hash_str_add_new(&_relevant_headers, str "", sizeof(str) - 1, &nullzv);

    ADD_RELEVANT_HEADER("x-forwarded-for");
    ADD_RELEVANT_HEADER("x-client-ip");
    ADD_RELEVANT_HEADER("x-real-ip");
    ADD_RELEVANT_HEADER("x-forwarded");
    ADD_RELEVANT_HEADER("x-cluster-client-ip");
    ADD_RELEVANT_HEADER("forwarded-for");
    ADD_RELEVANT_HEADER("forwarded");
    ADD_RELEVANT_HEADER("via");
    ADD_RELEVANT_HEADER("true-client-ip");
    ADD_RELEVANT_HEADER("content-length");
    ADD_RELEVANT_HEADER("content-type");
    ADD_RELEVANT_HEADER("content-encoding");
    ADD_RELEVANT_HEADER("content-language");
    ADD_RELEVANT_HEADER("host");
    ADD_RELEVANT_HEADER("user-agent");
    ADD_RELEVANT_HEADER("accept");
    ADD_RELEVANT_HEADER("accept-encoding");
    ADD_RELEVANT_HEADER("accept-language");

#undef ADD_RELEVANT_HEADER

    const char *extra_headers_orig = DDAPPSEC_G(extra_headers);
    char *extra_headers = estrdup(extra_headers_orig);

    for (char *p = extra_headers, *start = extra_headers; *p; p++) {
        char c = *p;
        size_t len;
        if (c == ',') {
            len = p - start;
        } else if (p[1] == '\0') {
            len = p - start + 1;
        } else {
            continue;
        }

        if (len > INT_MAX) {
            len = INT_MAX;
        }
        if (len > 0) {
            dd_string_normalize_header(start, len);
            mlog(dd_log_info,
                "Adding header '%.*s' to the list of relevant headers",
                (int)len, start);
            zend_hash_str_add_new(&_relevant_headers, start, len, &nullzv);
        }
        start = p + 1;
    }

    efree(extra_headers);
}

void dd_tags_shutdown()
{
    zend_hash_destroy(&_relevant_headers);
    _relevant_headers = (HashTable){0};
}

void dd_tags_rinit()
{
    bool init_list = false;
    if (UNEXPECTED(!_appsec_json_frags_inited)) {
        init_list = true;
        _appsec_json_frags_inited = true;
    } else if (UNEXPECTED(zend_llist_count(&_appsec_json_frags) > 0)) {
        mlog(dd_log_warning,
            "Previous request's appsec data tag fragments were not processed");
        init_list = true;
    }
    if (init_list) {
        zend_llist_init(&_appsec_json_frags, sizeof(zend_string *),
            _zend_string_release_indirect, 0);
    }
}

void dd_tags_add_appsec_json_frag(zend_string *nonnull zstr)
{
    zend_llist_add_element(&_appsec_json_frags, &zstr);
}

void dd_tags_rshutdown()
{
    zval *metrics_zv = dd_trace_root_span_get_metrics();
    if (metrics_zv) {
        // metric _dd.appsec.enabled
        bool added = _set_appsec_enabled(metrics_zv);
        if (added) {
            mlog(dd_log_debug, "Added metric %s", DD_METRIC_ENABLED);
        } else {
            mlog(dd_log_info, "Failed adding metric %s", DD_METRIC_ENABLED);
        }
    }
    // tag _dd.runtime_family
    _set_runtime_family();

    if (zend_llist_count(&_appsec_json_frags) == 0) {
        return;
    }

    zend_string *tag_value = _concat_json_fragments();
    zend_llist_clean(&_appsec_json_frags);

    zval tag_value_zv;
    ZVAL_STR(&tag_value_zv, tag_value);

    // tag _dd.appsec.json
    bool res = dd_trace_root_span_add_tag(_dd_tag_data_zstr, &tag_value_zv);
    if (!res) {
        mlog(dd_log_info, "Failed adding tag " DD_TAG_DATA " to root span");
        zval_ptr_dtor(&tag_value_zv);
        return;
    }

    // tag appsec.event
    res = dd_trace_root_span_add_tag(_dd_tag_event_zstr, &_true_zv);
    if (!res) {
        mlog(dd_log_info, "Failed adding tag " DD_TAG_EVENT " to root span");
        return;
    }

    // Add tags with request/response information
    if (!_add_ancillary_tags()) {
        return;
    }

    // metric _sampling_priority_v1
    if (metrics_zv) {
        _set_sampling_priority(metrics_zv);
        mlog(dd_log_debug, "Added/updated metric %s",
            DD_METRIC_SAMPLING_PRIORITY);
    }
}

void dd_tags_rshutdown_testing()
{
    // in testing, we don't add the data/event tags, but we still
    // need to clean the fragments to avoid leaking
    zend_llist_clean(&_appsec_json_frags);
}

static void _zend_string_release_indirect(void *s)
{
    zend_string_release(*(zend_string**)s);
}

static zend_string* _concat_json_fragments()
{
#define DD_DATA_TAG_BEFORE "{\"triggers\":["
#define DD_DATA_TAG_AFTER "]}"

    size_t count = zend_llist_count(&_appsec_json_frags);
    size_t needed_len = LSTRLEN(DD_DATA_TAG_BEFORE) +
                        LSTRLEN(DD_DATA_TAG_AFTER) +
                        (count > 0 ? (count - 1) : 0) /* for commas */;

    zend_llist_position pos;
    for (zend_string **sp = zend_llist_get_first_ex(&_appsec_json_frags, &pos);
         sp != NULL; sp = zend_llist_get_next_ex(&_appsec_json_frags, &pos)) {
        zend_string *s = *sp;
        needed_len += ZSTR_LEN(s);
    }

    zend_string *tag_value = zend_string_alloc(needed_len, 0);
    char *buf = ZSTR_VAL(tag_value);
    memcpy(buf, DD_DATA_TAG_BEFORE, LSTRLEN(DD_DATA_TAG_BEFORE));
    buf += LSTRLEN(DD_DATA_TAG_BEFORE);

    size_t i = 0;
    for (zend_string **sp = zend_llist_get_first_ex(&_appsec_json_frags, &pos);
         sp != NULL;
         sp = zend_llist_get_next_ex(&_appsec_json_frags, &pos), i++) {
        if (i != 0) {
            *buf++ = ',';
        }
        zend_string *s = *sp;
        size_t len = ZSTR_LEN(s);
        memcpy(buf, ZSTR_VAL(s), len);
        buf += len;
    }
    memcpy(buf, DD_DATA_TAG_AFTER, LSTRLEN(DD_DATA_TAG_AFTER));
    buf += LSTRLEN(DD_DATA_TAG_AFTER);
    *buf = '\0';

    return tag_value;
}

static void _add_all_tags_to_meta(zval *nonnull meta);
static void _dd_http_method(zend_array *meta_ht);
static void _dd_http_url(zend_array *meta_ht, zval *_server);
static void _dd_http_user_agent(zend_array *meta_ht, zval *_server);
static void _dd_http_status_code(zend_array *meta_ht);
static void _dd_http_network_client_ip(zend_array *meta_ht, zval *_server);
static void _dd_request_headers(zend_array *meta_ht, zval *_server);
static void _dd_response_headers(zend_array *meta_ht);

static bool _add_ancillary_tags()
{
    zval *nullable meta = dd_trace_root_span_get_meta();
    if (!meta) {
        return false;
    }

    _add_all_tags_to_meta(meta);
    return true;
}
static void _add_all_tags_to_meta(zval *nonnull meta)
{
    zend_array *meta_ht = Z_ARRVAL_P(meta);
    zval *_server =
        dd_php_get_autoglobal(TRACK_VARS_SERVER, LSTRARG("_SERVER"));
    if (!_server) {
        mlog(dd_log_info, "No SERVER autoglobal available");
        return;
    }

    _dd_http_method(meta_ht);
    _dd_http_url(meta_ht, _server);
    _dd_http_user_agent(meta_ht, _server);
    _dd_http_status_code(meta_ht);
    _dd_http_network_client_ip(meta_ht, _server);
    _dd_request_headers(meta_ht, _server);
    _dd_response_headers(meta_ht);
}

static void _add_new_zstr_to_meta(
    zend_array *meta_ht, zend_string *key, zend_string *val, bool copy)
{
    if (ZSTR_LEN(key) <= INT_MAX && ZSTR_LEN(val) <= INT_MAX) {
        mlog(dd_log_debug, "Adding tag '%.*s' with value '%.*s'",
            (int)ZSTR_LEN(key), ZSTR_VAL(key), (int)ZSTR_LEN(val),
            ZSTR_VAL(val));
    }

    if (copy) {
        zend_string_copy(val);
    }
    zval zv;
    ZVAL_STR(&zv, val);
    zend_hash_add_new(meta_ht, key, &zv);
}
static void _dd_http_method(zend_array *meta_ht)
{
    if (zend_hash_exists(meta_ht, _dd_tag_http_method_zstr)) {
        return;
    }
    const char *method = SG(request_info).request_method;
    if (!method) {
        return;
    }
    zend_string *method_zstr = zend_string_init(method, strlen(method), 0);
    _add_new_zstr_to_meta(
        meta_ht, _dd_tag_http_method_zstr, method_zstr, false);
}
static void _dd_http_url(zend_array *meta_ht, zval *_server)
{
    if (zend_hash_exists(meta_ht, _dd_tag_http_url_zstr)) {
        return;
    }

    // match what the tracer does for http.url: 1st try $_SERVER['REQUEST_URI'],
    // then SG(request_info).request_uri
    const char *uri = SG(request_info).request_uri;
    size_t uri_len;
    if (!uri) {
        const zend_string *uri_zstr =
            dd_php_get_string_elem(_server, _key_request_uri_zstr);
        if (!uri_zstr) {
            mlog(dd_log_info, "Could not determine request URI");
            return;
        }
        uri = ZSTR_VAL(uri_zstr);
        uri_len = ZSTR_LEN(uri_zstr);
    } else {
        uri_len = strlen(uri);
    }

    const zend_string *http_host_zstr =
        dd_php_get_string_elem(_server, _key_http_host_zstr);
    if (!http_host_zstr) {
        http_host_zstr = dd_php_get_string_elem(_server, _key_server_name_zstr);
        if (!http_host_zstr) {
            mlog(dd_log_info, "Could not determine hostname");
            return;
        }
    }

    bool has_https = zend_hash_exists(Z_ARRVAL_P(_server), _key_https_zstr);
    size_t final_len = (has_https ? LSTRLEN("https") : LSTRLEN("http")) +
                       LSTRLEN("://") + ZSTR_LEN(http_host_zstr) +
                       uri_len;
    smart_str url_str = {0};
    smart_str_alloc(&url_str, final_len, 0);
    smart_str_appendl_ex(&url_str, LSTRARG("http"), 0);
    if (has_https) {
        smart_str_appendc(&url_str, 's');
    }
    smart_str_appendl_ex(&url_str, LSTRARG("://"), 0);
    smart_str_append(&url_str, http_host_zstr);
    smart_str_appendl(&url_str, uri, uri_len);
    smart_str_0(&url_str);

    _add_new_zstr_to_meta(meta_ht, _dd_tag_http_url_zstr, url_str.s, false);
}

static void _dd_http_user_agent(zend_array *meta_ht, zval *_server)
{
    if (zend_hash_exists(meta_ht, _dd_tag_http_user_agent_zstr)) {
        return;
    }
    zend_string *http_user_agent_zstr =
        dd_php_get_string_elem(_server, _key_http_user_agent_zstr);
    if (!http_user_agent_zstr) {
        return;
    }

    _add_new_zstr_to_meta(
        meta_ht, _dd_tag_http_user_agent_zstr, http_user_agent_zstr, true);
}

static void _dd_http_status_code(zend_array *meta_ht)
{
    if (zend_hash_exists(meta_ht, _dd_tag_http_status_code_zstr)) {
        return;
    }

    int status = SG(sapi_headers).http_response_code;
    if (status == 0) {
        return;
    }

    zval zv;
    ZVAL_LONG(&zv, (zend_long)status);
    convert_to_string(&zv);

    _add_new_zstr_to_meta(
        meta_ht, _dd_tag_http_status_code_zstr, Z_STR(zv), false);
}

static void _dd_http_network_client_ip(zend_array *meta_ht, zval *_server)
{
    if (zend_hash_exists(meta_ht, _dd_tag_network_client_ip_zstr)) {
        return;
    }
    zend_string *remote_addr_zstr =
        dd_php_get_string_elem(_server, _key_remote_addr_zstr);
    if (!remote_addr_zstr) {
        return;
    }

    _add_new_zstr_to_meta(
        meta_ht, _dd_tag_network_client_ip_zstr, remote_addr_zstr, true);
}

static void _dd_request_headers(zend_array *meta_ht, zval *_server)
{
    // Pack headers
    zend_string *key;
    zval *val;
    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(_server), key, val) {
        if (!key) {
            continue;
        }

        if (Z_TYPE_P(val) != IS_STRING) {
            continue;
        }

        if (ZSTR_LEN(key) <= LSTRLEN("HTTP_") ||
            memcmp(ZSTR_VAL(key), LSTRARG("HTTP_")) != 0) {
            continue;
        }

        size_t header_name_len = ZSTR_LEN(key) - LSTRLEN("HTTP_");
        size_t tag_len = LSTRLEN(DD_PREFIX_TAG_REQUEST_HEADER) +
            header_name_len;

        zend_string *tag_name = zend_string_alloc(tag_len, 0);

        char *tag_p = ZSTR_VAL(tag_name);
        memcpy(tag_p, LSTRARG(DD_PREFIX_TAG_REQUEST_HEADER));
        tag_p += LSTRLEN(DD_PREFIX_TAG_REQUEST_HEADER);

        memcpy(tag_p, ZSTR_VAL(key) + LSTRLEN("HTTP_"), header_name_len);
        tag_p[header_name_len] = '\0';
        dd_string_normalize_header(tag_p, header_name_len);
        if (!zend_hash_str_exists(&_relevant_headers, tag_p, header_name_len)) {
            zend_string_efree(tag_name);
            continue;
        }

        Z_TRY_ADDREF_P(val);
        bool added = zend_hash_add(meta_ht, tag_name, val) != NULL;
        if (added) {
            mlog(dd_log_debug, "Adding request header tag '%s' -> '%s",
                ZSTR_VAL(tag_name), ZSTR_VAL(Z_STR_P(val)));
        } else {
            zval_delref_p(val);
        }
        zend_string_release(tag_name);
    }
    ZEND_HASH_FOREACH_END();
}

static void _dd_response_headers(zend_array *meta_ht)
{
    zend_llist *l = &SG(sapi_headers).headers;
    zend_llist_position pos;
    for (sapi_header_struct *header = zend_llist_get_first_ex(l, &pos); header;
         header = zend_llist_get_next_ex(l, &pos)) {
        const char *pcol = memchr(header->header, ':', header->header_len);
        if (!pcol) {
            if (header->header_len <= INT_MAX) {
                mlog(dd_log_debug, "Not a valid header: '%.*s'",
                    (int)header->header_len, header->header);
            }
            continue;
        }

        size_t header_name_len = pcol - header->header;
        size_t tag_len =
            LSTRLEN(DD_PREFIX_TAG_RESPONSE_HEADER) + header_name_len;

        zend_string *tag_name = zend_string_alloc(tag_len, 0);
        char *tag_p = ZSTR_VAL(tag_name);

        memcpy(tag_p, LSTRARG(DD_PREFIX_TAG_RESPONSE_HEADER));
        tag_p += LSTRLEN(DD_PREFIX_TAG_RESPONSE_HEADER);

        memcpy(tag_p, header->header, header_name_len);
        dd_string_normalize_header(tag_p, header_name_len);
        if (!zend_hash_str_exists(&_relevant_headers, tag_p, header_name_len)) {
            zend_string_efree(tag_name);
            continue;
        }

        tag_p += header_name_len;
        *tag_p = '\0';

        // skip spaces after colon
        const char *const end = header->header + header->header_len;
        const char *p;
        for (p = pcol + 1; p < end && *p == ' '; p++) {}

        zend_string *header_value = zend_string_init(p, end - p, 0);
        zval zv;
        ZVAL_STR(&zv, header_value);

        bool added = zend_hash_add(meta_ht, tag_name, &zv) != NULL;
        if (added) {
            mlog(dd_log_debug, "Adding response header tag '%s' -> '%s",
                ZSTR_VAL(tag_name), ZSTR_VAL(header_value));
        } else {
            zend_string_efree(header_value);
        }
        zend_string_release(tag_name);
    }
}

void _set_runtime_family()
{
    bool res = dd_trace_root_span_add_tag_str(
        LSTRARG(DD_TAG_RUNTIME_FAMILY), LSTRARG("php"));
    if (!res && !DDAPPSEC_G(testing)) {
        mlog(dd_log_warning,
            "Failed to add " DD_TAG_RUNTIME_FAMILY " to root span");
    }
}

static bool _set_appsec_enabled(zval *metrics_zv)
{
    zval zv;
    ZVAL_LONG(&zv, 1);
    return zend_hash_add(Z_ARRVAL_P(metrics_zv), _dd_metric_enabled, &zv) !=
           NULL;
}

static void _set_sampling_priority(zval *metrics_zv)
{
    zval zv;
    ZVAL_LONG(&zv, DD_SAMPLING_PRIORITY_USER_KEEP);
    zend_hash_update(Z_ARRVAL_P(metrics_zv),
               _dd_metric_sampling_prio_zstr, &zv);
}

static PHP_FUNCTION(datadog_appsec_testing_add_ancillary_tags)
{
    UNUSED(return_value);
    zval *arr;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "a/", &arr) == FAILURE) {
        return;
    }
    _add_all_tags_to_meta(arr);
}

// clang-format off
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(add_ancillary_tags, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(1, "dest", IS_ARRAY, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "add_ancillary_tags", PHP_FN(datadog_appsec_testing_add_ancillary_tags), add_ancillary_tags, 0)
    PHP_FE_END
};
// clang-format on

static void _register_test_functions()
{
    dd_phpobj_reg_funcs(functions);
}

