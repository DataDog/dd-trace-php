// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#include "tags.h"
#include "ddappsec.h"
#include "ddtrace.h"
#include "ext/pcre/php_pcre.h"
#include "ip_extraction.h"
#include "logging.h"
#include "php_compat.h"
#include "php_helpers.h"
#include "php_objects.h"
#include "string_helpers.h"
#include "user_tracking.h"
#include <SAPI.h>
#include <Zend/zend.h>
#include <zend_smart_str.h>
#include <zend_types.h>

#if PHP_VERSION_ID < 70200
#    define zend_strpprintf strpprintf
#endif

#define DD_TAG_DATA "_dd.appsec.json"
#define DD_TAG_EVENT "appsec.event"
#define DD_TAG_BLOCKED "appsec.blocked"
#define DD_TAG_RUNTIME_FAMILY "_dd.runtime_family"
#define DD_TAG_HTTP_METHOD "http.method"
#define DD_TAG_HTTP_USER_AGENT "http.useragent"
#define DD_TAG_HTTP_STATUS_CODE "http.status_code"
#define DD_TAG_HTTP_URL "http.url"
#define DD_TAG_NETWORK_CLIENT_IP "network.client.ip"
#define DD_PREFIX_TAG_REQUEST_HEADER "http.request.headers."
#define DD_TAG_HTTP_RH_CONTENT_LENGTH "http.response.headers.content-length"
#define DD_TAG_HTTP_RH_CONTENT_TYPE "http.response.headers.content-type"
#define DD_TAG_HTTP_RH_CONTENT_ENCODING "http.response.headers.content-encoding"
#define DD_TAG_HTTP_RH_CONTENT_LANGUAGE "http.response.headers.content-language"
#define DD_TAG_HTTP_CLIENT_IP "http.client_ip"
#define DD_TAG_USER_ID "usr.id"
#define DD_MULTIPLE_IP_HEADERS "_dd.multiple-ip-headers"
#define DD_METRIC_ENABLED "_dd.appsec.enabled"
#define DD_APPSEC_EVENTS_PREFIX "appsec.events."
#define DD_SIGNUP_EVENT DD_APPSEC_EVENTS_PREFIX "users.signup"
#define DD_LOGIN_SUCCESS_EVENT DD_APPSEC_EVENTS_PREFIX "users.login.success"
#define DD_LOGIN_FAILURE_EVENT DD_APPSEC_EVENTS_PREFIX "users.login.failure"
#define DD_EVENTS_USER_SIGNUP_AUTO_MODE                                        \
    "_dd.appsec.events.users.signup.auto.mode"
#define DD_EVENTS_USER_LOGIN_SUCCESS_AUTO_MODE                                 \
    "_dd.appsec.events.users.login.success.auto.mode"
#define DD_EVENTS_USER_LOGIN_FAILURE_AUTO_MODE                                 \
    "_dd.appsec.events.users.login.failure.auto.mode"
#define DD_EVENTS_USER_SIGNUP_SDK "_dd.appsec.events.users.signup.sdk"
#define DD_EVENTS_USER_LOGIN_SUCCESS_SDK                                       \
    "_dd.appsec.events.users.login.success.sdk"
#define DD_EVENTS_USER_LOGIN_FAILURE_SDK                                       \
    "_dd.appsec.events.users.login.failure.sdk"

typedef enum _automated_user_events_tracking_mode {
    NOT_ENABLED = 0,
    SAFE,
    EXTENDED
} automated_user_events_tracking_mode;
static THREAD_LOCAL_ON_ZTS automated_user_events_tracking_mode
    automated_user_events_tracking = SAFE;
static zend_string *_mode_safe_cstr;
static zend_string *_mode_extended_cstr;
static THREAD_LOCAL_ON_ZTS zend_string *_mode_cstr;

static zend_string *_dd_tag_data_zstr;
static zend_string *_dd_tag_event_zstr;
static zend_string *_dd_tag_blocked_zstr;
static zend_string *_dd_tag_http_method_zstr;
static zend_string *_dd_tag_http_user_agent_zstr;
static zend_string *_dd_tag_http_status_code_zstr;
static zend_string *_dd_tag_http_url_zstr;
static zend_string *_dd_tag_network_client_ip_zstr;
static zend_string *_dd_tag_http_client_ip_zstr;
static zend_string *_dd_tag_rh_content_length;   // response
static zend_string *_dd_tag_rh_content_type;     // response
static zend_string *_dd_tag_rh_content_encoding; // response
static zend_string *_dd_tag_rh_content_language; // response
static zend_string *_dd_tag_user_id;
static zend_string *_dd_multiple_ip_headers;
static zend_string *_dd_metric_enabled;
static zend_string *_dd_signup_event;
static zend_string *_dd_login_success_event;
static zend_string *_dd_login_failure_event;
static zend_string *_dd_login_failure_event;
static zend_string *_dd_signup_event_auto_mode;
static zend_string *_dd_login_success_event_auto_mode;
static zend_string *_dd_login_failure_event_auto_mode;
static zend_string *_dd_signup_event_sdk;
static zend_string *_dd_login_success_event_sdk;
static zend_string *_dd_login_failure_event_sdk;
static zend_string *_key_request_uri_zstr;
static zend_string *_key_http_host_zstr;
static zend_string *_key_server_name_zstr;
static zend_string *_key_http_user_agent_zstr;
static zend_string *_key_https_zstr;
static zend_string *_key_remote_addr_zstr;
static zend_string *_true_zstr;
static zend_string *_false_zstr;
static zend_string *_track_zstr;
static zend_string *_usr_exists_zstr;
static zend_string *_uuid_zstr;
static zend_string *_id_zstr;
static HashTable _relevant_headers;
static HashTable _relevant_ip_headers;
static THREAD_LOCAL_ON_ZTS bool _appsec_json_frags_inited;
static THREAD_LOCAL_ON_ZTS zend_llist _appsec_json_frags;
static THREAD_LOCAL_ON_ZTS zend_string *nullable _event_user_id;
static THREAD_LOCAL_ON_ZTS bool _blocked;
static THREAD_LOCAL_ON_ZTS bool _force_keep;

static void _init_relevant_headers(void);
static zend_string *_concat_json_fragments(void);
static void _zend_string_release_indirect(void *s);
static void _add_basic_ancillary_tags(void);
static bool _add_all_ancillary_tags(void);
void _set_runtime_family(void);
static bool _set_appsec_enabled(zval *metrics_zv);
static void _register_functions(void);
static void _register_test_functions(void);

void dd_tags_startup()
{
    _dd_tag_data_zstr =
        zend_string_init_interned(LSTRARG(DD_TAG_DATA), 1 /* permanent */);
    _dd_tag_event_zstr =
        zend_string_init_interned(LSTRARG(DD_TAG_EVENT), 1 /* permanent */);
    _dd_tag_blocked_zstr =
        zend_string_init_interned(LSTRARG(DD_TAG_BLOCKED), 1 /* permanent */);
    _true_zstr = zend_string_init_interned(LSTRARG("true"), 1 /* permanent */);
    _false_zstr =
        zend_string_init_interned(LSTRARG("false"), 1 /* permanent */);

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
    _dd_tag_http_client_ip_zstr =
        zend_string_init_interned(LSTRARG(DD_TAG_HTTP_CLIENT_IP), 1);

    _dd_tag_rh_content_length =
        zend_string_init_interned(LSTRARG(DD_TAG_HTTP_RH_CONTENT_LENGTH), 1);
    _dd_tag_rh_content_type =
        zend_string_init_interned(LSTRARG(DD_TAG_HTTP_RH_CONTENT_TYPE), 1);
    _dd_tag_rh_content_encoding =
        zend_string_init_interned(LSTRARG(DD_TAG_HTTP_RH_CONTENT_ENCODING), 1);
    _dd_tag_rh_content_language =
        zend_string_init_interned(LSTRARG(DD_TAG_HTTP_RH_CONTENT_LANGUAGE), 1);
    _dd_tag_user_id = zend_string_init_interned(LSTRARG(DD_TAG_USER_ID), 1);

    _dd_multiple_ip_headers =
        zend_string_init_interned(LSTRARG(DD_MULTIPLE_IP_HEADERS), 1);
    _dd_metric_enabled =
        zend_string_init_interned(LSTRARG(DD_METRIC_ENABLED), 1);

    _key_request_uri_zstr =
        zend_string_init_interned(LSTRARG("REQUEST_URI"), 1);
    _key_http_host_zstr = zend_string_init_interned(LSTRARG("HTTP_HOST"), 1);
    _key_server_name_zstr =
        zend_string_init_interned(LSTRARG("SERVER_NAME"), 1);
    _key_http_user_agent_zstr =
        zend_string_init_interned(LSTRARG("HTTP_USER_AGENT"), 1);
    _key_https_zstr = zend_string_init_interned(LSTRARG("HTTPS"), 1);
    _key_remote_addr_zstr =
        zend_string_init_interned(LSTRARG("REMOTE_ADDR"), 1);

    // Event related strings
    _track_zstr =
        zend_string_init_interned(LSTRARG("track"), 1 /* permanent */);
    _dd_signup_event = zend_string_init_interned(LSTRARG(DD_SIGNUP_EVENT), 1);
    _dd_login_success_event =
        zend_string_init_interned(LSTRARG(DD_LOGIN_SUCCESS_EVENT), 1);
    _dd_login_failure_event =
        zend_string_init_interned(LSTRARG(DD_LOGIN_FAILURE_EVENT), 1);
    _dd_signup_event_auto_mode =
        zend_string_init_interned(LSTRARG(DD_EVENTS_USER_SIGNUP_AUTO_MODE), 1);
    _dd_login_success_event_auto_mode = zend_string_init_interned(
        LSTRARG(DD_EVENTS_USER_LOGIN_SUCCESS_AUTO_MODE), 1);
    _dd_login_failure_event_auto_mode = zend_string_init_interned(
        LSTRARG(DD_EVENTS_USER_LOGIN_FAILURE_AUTO_MODE), 1);
    _dd_signup_event_sdk =
        zend_string_init_interned(LSTRARG(DD_EVENTS_USER_SIGNUP_SDK), 1);
    _dd_login_success_event_sdk =
        zend_string_init_interned(LSTRARG(DD_EVENTS_USER_LOGIN_SUCCESS_SDK), 1);
    _dd_login_failure_event_sdk =
        zend_string_init_interned(LSTRARG(DD_EVENTS_USER_LOGIN_FAILURE_SDK), 1);
    _usr_exists_zstr =
        zend_string_init_interned(LSTRARG("usr.exists"), 1 /* permanent */);

    _uuid_zstr = zend_string_init_interned(
        LSTRARG(
            "/^[\\da-f]{8}-[\\da-f]{4}-[\\da-f]{4}-[\\da-f]{4}-[\\da-f]{12}$/"),
        1 /* permanent */);
    _id_zstr =
        zend_string_init_interned(LSTRARG("/^\\d+$/"), 1 /* permanent */);

    _mode_safe_cstr =
        zend_string_init_interned(LSTRARG("safe"), 1 /* permanent */);
    _mode_extended_cstr =
        zend_string_init_interned(LSTRARG("extended"), 1 /* permanent */);
    _mode_cstr = _mode_safe_cstr; // default

    _init_relevant_headers();

    _register_functions();

    if (get_global_DD_APPSEC_TESTING()) {
        _register_test_functions();
    }
}
static void _init_relevant_headers()
{
    zend_hash_init(&_relevant_headers, 32, NULL, NULL, 1);
    zend_hash_init(&_relevant_ip_headers, 32, NULL, NULL, 1);
    zval nullzv;
    ZVAL_NULL(&nullzv);
#define ADD_RELEVANT_HEADER(str)                                               \
    zend_hash_str_add_new(&_relevant_headers, str "", sizeof(str) - 1, &nullzv);
#define ADD_RELEVANT_IP_HEADER(str)                                            \
    zend_hash_str_add_new(                                                     \
        &_relevant_ip_headers, str "", sizeof(str) - 1, &nullzv);

    ADD_RELEVANT_IP_HEADER("x-forwarded-for");
    ADD_RELEVANT_IP_HEADER("x-real-ip");
    ADD_RELEVANT_IP_HEADER("client-ip");
    ADD_RELEVANT_IP_HEADER("x-forwarded");
    ADD_RELEVANT_IP_HEADER("x-cluster-client-ip");
    ADD_RELEVANT_IP_HEADER("forwarded-for");
    ADD_RELEVANT_IP_HEADER("forwarded");
    ADD_RELEVANT_IP_HEADER("true-client-ip");
    ADD_RELEVANT_IP_HEADER("via");

    ADD_RELEVANT_HEADER("x-forwarded-for");
    ADD_RELEVANT_HEADER("x-client-ip");
    ADD_RELEVANT_HEADER("x-real-ip");
    ADD_RELEVANT_HEADER("client-ip");
    ADD_RELEVANT_HEADER("x-forwarded");
    ADD_RELEVANT_HEADER("x-cluster-client-ip");
    ADD_RELEVANT_HEADER("forwarded-for");
    ADD_RELEVANT_HEADER("forwarded");
    ADD_RELEVANT_HEADER("true-client-ip");
    ADD_RELEVANT_HEADER("via");
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

    zend_hash_copy(
        &_relevant_headers, get_global_DD_APPSEC_EXTRA_HEADERS(), NULL);
}

void dd_tags_shutdown()
{
    zend_hash_destroy(&_relevant_headers);
    _relevant_headers = (HashTable){0};

    zend_hash_destroy(&_relevant_ip_headers);
    _relevant_ip_headers = (HashTable){0};
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

    // Just in case...
    _event_user_id = NULL;
    _blocked = false;
    _force_keep = false;
}

void dd_tags_add_appsec_json_frag(zend_string *nonnull zstr)
{
    zend_llist_add_element(&_appsec_json_frags, &zstr);
}

void dd_tags_set_event_user_id(zend_string *nonnull zstr)
{
    _event_user_id = zend_string_copy(zstr);
}

void dd_tags_rshutdown()
{
    zend_llist_clean(&_appsec_json_frags);

    if (_event_user_id) {
        zend_string_release(_event_user_id);
        _event_user_id = NULL;
    }
}

void dd_tags_add_tags()
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

    if (_force_keep) {
        dd_trace_set_priority_sampling_on_root(
            PRIORITY_SAMPLING_USER_KEEP, DD_MECHANISM_MANUAL);
        mlog(dd_log_debug, "Updated sampling priority to user_keep");
    }

    if (zend_llist_count(&_appsec_json_frags) == 0) {
        _add_basic_ancillary_tags();
        return;
    }

    zend_string *tag_value = _concat_json_fragments();

    zval tag_value_zv;
    ZVAL_STR(&tag_value_zv, tag_value);

    // tag _dd.appsec.json
    bool res = dd_trace_root_span_add_tag(_dd_tag_data_zstr, &tag_value_zv);
    if (!res) {
        mlog(dd_log_info, "Failed adding tag " DD_TAG_DATA " to root span");
        return;
    }

    // tag appsec.event
    zval true_zv;
    ZVAL_STR_COPY(&true_zv, _true_zstr);
    res = dd_trace_root_span_add_tag(_dd_tag_event_zstr, &true_zv);
    if (!res) {
        mlog(dd_log_info, "Failed adding tag " DD_TAG_EVENT " to root span");
        return;
    }

    // Add tags with request/response information
    if (!_add_all_ancillary_tags()) {
        return;
    }
}

void dd_tags_add_blocked() { _blocked = true; }

void dd_tags_rshutdown_testing()
{
    // in testing, we don't add the data/event tags, but we still
    // need to clean the fragments to avoid leaking
    dd_tags_rshutdown();
}

void dd_tags_set_sampling_priority() { _force_keep = true; }

static void _zend_string_release_indirect(void *s)
{
    zend_string_release(*(zend_string **)s);
}

static zend_string *_concat_json_fragments()
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

static void _add_basic_tags_to_meta(zval *nonnull meta);
static void _add_all_tags_to_meta(zval *nonnull meta);
static void _dd_http_method(zend_array *meta_ht);
static void _dd_http_url(zend_array *meta_ht, zval *_server);
static void _dd_http_user_agent(zend_array *meta_ht, zval *_server);
static void _dd_http_status_code(zend_array *meta_ht);
static void _dd_http_network_client_ip(zend_array *meta_ht, zval *_server);
static void _dd_http_client_ip(zend_array *meta_ht);
static void _dd_request_headers(
    zend_array *meta_ht, zval *_server, HashTable *relevant_headers);
static void _dd_response_headers(zend_array *meta_ht);
static void _dd_event_user_id(zend_array *meta_ht);
static void _dd_appsec_blocked(zend_array *meta_ht);

static void _add_basic_ancillary_tags()
{
    zval *nullable meta = dd_trace_root_span_get_meta();
    if (!meta) {
        return;
    }

    _add_basic_tags_to_meta(meta);
}

static bool _add_all_ancillary_tags()
{
    zval *nullable meta = dd_trace_root_span_get_meta();
    if (!meta) {
        return false;
    }

    _add_all_tags_to_meta(meta);
    return true;
}

static void _add_basic_tags_to_meta(zval *nonnull meta)
{
    zend_array *meta_ht = Z_ARRVAL_P(meta);
    zval *_server =
        dd_php_get_autoglobal(TRACK_VARS_SERVER, LSTRARG("_SERVER"));
    if (!_server) {
        mlog(dd_log_info, "No SERVER autoglobal available");
        return;
    }

    _dd_http_client_ip(meta_ht);
    _dd_request_headers(meta_ht, _server, &_relevant_ip_headers);
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
    _dd_request_headers(meta_ht, _server, &_relevant_headers);
    _dd_http_client_ip(meta_ht);
    _dd_response_headers(meta_ht);
    _dd_event_user_id(meta_ht);
    _dd_appsec_blocked(meta_ht);
}

static void _add_new_zstr_to_meta(zend_array *meta_ht, zend_string *key,
    zend_string *val, bool copy, bool override)
{
    if (ZSTR_LEN(key) <= INT_MAX && ZSTR_LEN(val) <= INT_MAX) {
        mlog(dd_log_debug, "Adding tag '%.*s' with value '%.*s'",
            (int)ZSTR_LEN(key), ZSTR_VAL(key), (int)ZSTR_LEN(val),
            ZSTR_VAL(val));
    }

    if (copy) {
        zend_string_copy(val);
    }
    zval *added = NULL;
    zval zv;
    ZVAL_STR(&zv, val);
    if (override) {
        added = zend_hash_update(meta_ht, key, &zv);
    } else {
        added = zend_hash_add(meta_ht, key, &zv);
    }

    if (copy && added == NULL) {
        zend_string_release(val);
    }
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
        meta_ht, _dd_tag_http_method_zstr, method_zstr, false, false);
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
                       LSTRLEN("://") + ZSTR_LEN(http_host_zstr) + uri_len;
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

    _add_new_zstr_to_meta(
        meta_ht, _dd_tag_http_url_zstr, url_str.s, false, false);
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

    _add_new_zstr_to_meta(meta_ht, _dd_tag_http_user_agent_zstr,
        http_user_agent_zstr, true, false);
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
        meta_ht, _dd_tag_http_status_code_zstr, Z_STR(zv), false, false);
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
        meta_ht, _dd_tag_network_client_ip_zstr, remote_addr_zstr, true, false);
}

static void _dd_http_client_ip(zend_array *meta_ht)
{
    if (zend_hash_exists(meta_ht, _dd_tag_http_client_ip_zstr) ||
        zend_hash_exists(meta_ht, _dd_multiple_ip_headers)) {
        return;
    }
    zend_string *client_ip = dd_ip_extraction_get_ip();
    if (client_ip) {
        _add_new_zstr_to_meta(
            meta_ht, _dd_tag_http_client_ip_zstr, client_ip, true, false);
    }
}

static void _dd_request_headers(
    zend_array *meta_ht, zval *_server, HashTable *relevant_headers)
{
    // Pack headers
    zend_string *key;
    zval *val;
    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(_server), key, val)
    {
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
        size_t tag_len =
            LSTRLEN(DD_PREFIX_TAG_REQUEST_HEADER) + header_name_len;

        zend_string *tag_name = zend_string_alloc(tag_len, 0);

        char *tag_p = ZSTR_VAL(tag_name);
        memcpy(tag_p, LSTRARG(DD_PREFIX_TAG_REQUEST_HEADER));
        tag_p += LSTRLEN(DD_PREFIX_TAG_REQUEST_HEADER);

        memcpy(tag_p, ZSTR_VAL(key) + LSTRLEN("HTTP_"), header_name_len);
        tag_p[header_name_len] = '\0';
        dd_string_normalize_header(tag_p, header_name_len);
        if (!zend_hash_str_exists(relevant_headers, tag_p, header_name_len)) {
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

static zend_string *nullable _is_relevant_resp_header(
    const char *name, size_t name_len);
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
        zend_string *const tag_name =
            _is_relevant_resp_header(header->header, header_name_len);
        if (!tag_name) {
            continue;
        }

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
    }
}

static void _dd_event_user_id(zend_array *meta_ht)
{
    if (_event_user_id) {
        _add_new_zstr_to_meta(
            meta_ht, _dd_tag_user_id, _event_user_id, true, false);
    }
}

static void _dd_appsec_blocked(zend_array *meta_ht)
{
    if (_blocked) {
        _add_new_zstr_to_meta(
            meta_ht, _dd_tag_blocked_zstr, _true_zstr, true, false);
    }
}

static zend_string *nullable _is_relevant_resp_header(
    const char *name, size_t name_len)
{
    // content-{length,type,encoding,language}
    if (!dd_string_starts_with_lc(name, name_len, ZEND_STRL("content-"))) {
        return NULL;
    }
    const char *const rest = name + LSTRLEN("content-");
    size_t rest_len = name_len - LSTRLEN("content-");
    if (dd_string_equals_lc(rest, rest_len, ZEND_STRL("length"))) {
        return _dd_tag_rh_content_length;
    }
    if (dd_string_equals_lc(rest, rest_len, ZEND_STRL("type"))) {
        return _dd_tag_rh_content_type;
    }
    if (dd_string_equals_lc(rest, rest_len, ZEND_STRL("encoding"))) {
        return _dd_tag_rh_content_encoding;
    }
    if (dd_string_equals_lc(rest, rest_len, ZEND_STRL("language"))) {
        return _dd_tag_rh_content_language;
    }
    return NULL;
}

void _set_runtime_family()
{
    bool res = dd_trace_root_span_add_tag_str(
        LSTRARG(DD_TAG_RUNTIME_FAMILY), LSTRARG("php"));
    if (!res && !get_global_DD_APPSEC_TESTING()) {
        mlog(dd_log_warning,
            "Failed to add " DD_TAG_RUNTIME_FAMILY " to root span");
    }
}

static void _add_custom_event_keyval(zend_array *nonnull meta_ht,
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    zend_string *nonnull event, zend_string *nonnull key,
    zend_string *nonnull value, bool copy, bool override)
{
    size_t final_len = ZSTR_LEN(event) + LSTRLEN(".") + ZSTR_LEN(key);

    smart_str key_str = {0};
    smart_str_alloc(&key_str, final_len, 0);
    smart_str_append_ex(&key_str, event, 0);
    smart_str_appendc_ex(&key_str, '.', 0);
    smart_str_append_ex(&key_str, key, 0);
    smart_str_0(&key_str);

    _add_new_zstr_to_meta(meta_ht, key_str.s, value, copy, override);
    smart_str_free(&key_str);
}

static void _add_custom_event_metadata(zend_array *nonnull meta_ht,
    zend_string *nonnull event, HashTable *nullable metadata, bool override)
{
    if (metadata == NULL) {
        return;
    }

    zend_string *key = NULL;
    zval *value = NULL;
    ZEND_HASH_FOREACH_STR_KEY_VAL(metadata, key, value)
    {
        if (!key || Z_TYPE_P(value) != IS_STRING) {
            continue;
        }
        _add_custom_event_keyval(
            meta_ht, event, key, Z_STR_P(value), true, override);
    }
    ZEND_HASH_FOREACH_END();
}

// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
bool match_regex(zend_string *pattern, zend_string *subject)
{
    if (ZSTR_LEN(pattern) == 0) {
        return false;
    }

    pcre_cache_entry *pce = pcre_get_compiled_regex_cache(pattern);
    zval ret;
#if PHP_VERSION_ID < 70400
    php_pcre_match_impl(
        pce, ZSTR_VAL(subject), ZSTR_LEN(subject), &ret, NULL, 0, 0, 0, 0);
#else
    php_pcre_match_impl(pce, subject, &ret, NULL, 0, 0, 0, 0);
#endif
    return Z_TYPE(ret) == IS_LONG && Z_LVAL(ret) > 0;
}

bool is_user_id_sensitive(zend_string *user_id)
{
    return !(
        match_regex(_uuid_zstr, user_id) || match_regex(_id_zstr, user_id));
}

static PHP_FUNCTION(datadog_appsec_track_user_signup_event)
{
    UNUSED(return_value);
    if (DDAPPSEC_G(enabled) != ENABLED) {
        mlog(dd_log_debug, "Trying to access to track_user_signup_event "
                           "function while appsec is disabled");
        return;
    }

    zend_string *user_id = NULL;
    HashTable *metadata = NULL;
    zend_bool automated = false; // Don't document. Only internal usage
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S|hb", &user_id, &metadata,
            &automated) == FAILURE) {
        mlog(dd_log_warning, "Unexpected parameter combination, expected "
                             "(user_id, metadata)");
        return;
    }
    if (automated) {
        if (automated_user_events_tracking == NOT_ENABLED) {
            return;
        }

        if (automated_user_events_tracking == SAFE) {
            if (user_id != NULL && is_user_id_sensitive(user_id)) {
                user_id = NULL;
            }

            if (metadata != NULL && zend_array_count(metadata) > 0) {
                metadata = NULL;
            }
        }
    } else {
        if (user_id == NULL || ZSTR_LEN(user_id) == 0) {
            mlog(dd_log_warning, "Unexpected empty user id");
            return;
        }
    }

    zval *nullable meta = dd_trace_root_span_get_meta();
    if (!meta) {
        mlog(dd_log_warning, "Failed to retrieve root span meta");
        return;
    }
    zend_array *meta_ht = Z_ARRVAL_P(meta);
    bool override = !automated;

    if (user_id && ZSTR_LEN(user_id) > 0) {
        // usr.id = <user_id>
        _add_new_zstr_to_meta(
            meta_ht, _dd_tag_user_id, user_id, true, override);
    }

    if (automated) {
        // _dd.appsec.events.users.signup.auto.mode =
        // <DD_APPSEC_AUTOMATED_USER_EVENTS_TRACKING>
        if (_mode_cstr && automated_user_events_tracking != NOT_ENABLED) {
            _add_new_zstr_to_meta(meta_ht, _dd_signup_event_auto_mode,
                _mode_cstr, true, override);
        }
    } else {
        // _dd.appsec.events.users.signup.sdk = true
        _add_new_zstr_to_meta(
            meta_ht, _dd_signup_event_sdk, _true_zstr, true, override);
    }

    // appsec.events.users.signup.<key> = <value>
    _add_custom_event_metadata(meta_ht, _dd_signup_event, metadata, override);

    // appsec.events.users.login.success.track = true
    _add_custom_event_keyval(
        meta_ht, _dd_signup_event, _track_zstr, _true_zstr, true, override);

    dd_tags_set_sampling_priority();
}

static PHP_FUNCTION(datadog_appsec_track_user_login_success_event)
{
    UNUSED(return_value);
    if (DDAPPSEC_G(enabled) != ENABLED) {
        mlog(dd_log_debug, "Trying to access to track_user_login_success_event "
                           "function while appsec is disabled");
        return;
    }

    zend_string *user_id = NULL;
    HashTable *metadata = NULL;
    zend_bool automated = false; // Don't document. Only internal usage
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S|hb", &user_id, &metadata,
            &automated) == FAILURE) {
        mlog(dd_log_warning, "Unexpected parameter combination, expected "
                             "(user_id, metadata)");
        return;
    }
    if (automated) {
        if (automated_user_events_tracking == NOT_ENABLED) {
            return;
        }

        if (automated_user_events_tracking == SAFE) {
            if (user_id != NULL && is_user_id_sensitive(user_id)) {
                user_id = NULL;
            }

            if (metadata != NULL && zend_array_count(metadata) > 0) {
                metadata = NULL;
            }
        }
    } else {
        if (user_id == NULL || ZSTR_LEN(user_id) == 0) {
            mlog(dd_log_warning, "Unexpected empty user id");
            return;
        }
    }

    zval *nullable meta = dd_trace_root_span_get_meta();
    if (!meta) {
        mlog(dd_log_warning, "Failed to retrieve root span meta");
        return;
    }
    zend_array *meta_ht = Z_ARRVAL_P(meta);
    bool override = !automated;

    if (user_id && ZSTR_LEN(user_id) > 0) {
        dd_find_and_apply_verdict_for_user(user_id);
        // usr.id = <user_id>
        _add_new_zstr_to_meta(
            meta_ht, _dd_tag_user_id, user_id, true, override);
    }

    if (automated) {
        // _dd.appsec.events.users.login.success.auto.mode =
        // <DD_APPSEC_AUTOMATED_USER_EVENTS_TRACKING>
        if (_mode_cstr && automated_user_events_tracking != NOT_ENABLED) {
            _add_new_zstr_to_meta(meta_ht, _dd_login_success_event_auto_mode,
                _mode_cstr, true, override);
        }
    } else {
        // _dd.appsec.events.users.login.success.sdk = true
        _add_new_zstr_to_meta(
            meta_ht, _dd_login_success_event_sdk, _true_zstr, true, override);
    }

    // appsec.events.users.login.success.track = true
    _add_custom_event_keyval(meta_ht, _dd_login_success_event, _track_zstr,
        _true_zstr, true, override);

    // appsec.events.users.login.success.<key> = <value>
    _add_custom_event_metadata(
        meta_ht, _dd_login_success_event, metadata, override);

    dd_tags_set_sampling_priority();
}

static PHP_FUNCTION(datadog_appsec_track_user_login_failure_event)
{
    UNUSED(return_value);
    if (DDAPPSEC_G(enabled) != ENABLED) {
        mlog(dd_log_debug, "Trying to access to track_user_login_failure_event "
                           "function while appsec is disabled");
        return;
    }

    zend_string *user_id = NULL;
    zend_bool exists = false;
    HashTable *metadata = NULL;
    zend_bool automated = false; // Don't document. Only internal usage
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "Sb|hb", &user_id, &exists,
            &metadata, &automated) == FAILURE) {
        mlog(dd_log_warning, "Unexpected parameter combination, expected "
                             "(user_id, exists, metadata)");
        return;
    }

    if (automated) {
        if (automated_user_events_tracking == NOT_ENABLED) {
            return;
        }

        if (automated_user_events_tracking == SAFE) {
            if (is_user_id_sensitive(user_id)) {
                user_id = NULL;
            }

            if (metadata != NULL && zend_array_count(metadata) > 0) {
                metadata = NULL;
            }
        }
    }

    zval *nullable meta = dd_trace_root_span_get_meta();
    if (!meta) {
        mlog(dd_log_warning, "Failed to retrieve root span meta");
        return;
    }
    zend_array *meta_ht = Z_ARRVAL_P(meta);
    bool override = !automated;

    if (user_id != NULL && ZSTR_LEN(user_id) > 0) {
        // appsec.events.users.login.failure.usr.id = <user_id>
        _add_custom_event_keyval(meta_ht, _dd_login_failure_event,
            _dd_tag_user_id, user_id, true, override);
    }

    // appsec.events.users.login.failure.track = true
    _add_custom_event_keyval(meta_ht, _dd_login_failure_event, _track_zstr,
        _true_zstr, true, override);

    if (automated) {
        // _dd.appsec.events.users.login.failure.auto.mode =
        // <DD_APPSEC_AUTOMATED_USER_EVENTS_TRACKING>
        if (_mode_cstr && automated_user_events_tracking != NOT_ENABLED) {
            _add_new_zstr_to_meta(meta_ht, _dd_login_failure_event_auto_mode,
                _mode_cstr, true, override);
        }
    } else {
        // _dd.appsec.events.users.login.success.sdk = true
        _add_new_zstr_to_meta(
            meta_ht, _dd_login_failure_event_sdk, _true_zstr, true, override);
    }

    // appsec.events.users.login.failure.usr.exists = <exists>
    _add_custom_event_keyval(meta_ht, _dd_login_failure_event, _usr_exists_zstr,
        exists ? _true_zstr : _false_zstr, true, override);

    // appsec.events.users.login.failure.<key> = <value>
    _add_custom_event_metadata(
        meta_ht, _dd_login_failure_event, metadata, override);

    dd_tags_set_sampling_priority();
}

static PHP_FUNCTION(datadog_appsec_track_custom_event)
{
    UNUSED(return_value);
    if (DDAPPSEC_G(enabled) != ENABLED) {
        mlog(dd_log_debug, "Trying to access to track_custom_event "
                           "function while appsec is disabled");
        return;
    }

    zend_string *event_name = NULL;
    HashTable *metadata = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "S|h", &event_name, &metadata) ==
        FAILURE) {
        mlog(dd_log_warning, "Unexpected parameter combination, expected "
                             "(event_name, metadata)");
        return;
    }

    if (event_name == NULL || ZSTR_LEN(event_name) == 0) {
        mlog(dd_log_warning, "Unexpected empty event name");
        return;
    }

    zval *nullable meta = dd_trace_root_span_get_meta();
    if (!meta) {
        mlog(dd_log_warning, "Failed to retrieve root span meta");
        return;
    }
    zend_array *meta_ht = Z_ARRVAL_P(meta);

    // Generate full event name
    size_t event_len = LSTRLEN(DD_APPSEC_EVENTS_PREFIX) + ZSTR_LEN(event_name);
    smart_str event_str = {0};
    smart_str_alloc(&event_str, event_len, 0);
    smart_str_appendl_ex(&event_str, LSTRARG(DD_APPSEC_EVENTS_PREFIX), 0);
    smart_str_append_ex(&event_str, event_name, 0);
    smart_str_0(&event_str);

    // appsec.events.<event>.track = true
    _add_custom_event_keyval(
        meta_ht, event_str.s, _track_zstr, _true_zstr, true, true);

    // appsec.events.<event>.<key> = <value>
    _add_custom_event_metadata(meta_ht, event_str.s, metadata, true);

    smart_str_free(&event_str);

    dd_tags_set_sampling_priority();
}

static bool _set_appsec_enabled(zval *metrics_zv)
{
    zval zv;
    ZVAL_LONG(&zv, DDAPPSEC_G(enabled) == ENABLED ? 1 : 0);
    return zend_hash_add(Z_ARRVAL_P(metrics_zv), _dd_metric_enabled, &zv) !=
           NULL;
}

bool dd_parse_automated_user_events_tracking(
    zai_str value, zval *nonnull decoded_value, bool persistent)
{
    if (!value.len) {
        return false;
    }

    bool result = false;
    size_t len = strlen((const char *)value.ptr);
    if (dd_string_equals_lc(value.ptr, len, ZEND_STRL("safe"))) {
        automated_user_events_tracking = SAFE;
        result = true;
        _mode_cstr = _mode_safe_cstr;
    } else if (dd_string_equals_lc(value.ptr, len, ZEND_STRL("extended"))) {
        automated_user_events_tracking = EXTENDED;
        result = true;
        _mode_cstr = _mode_extended_cstr;
    } else if (dd_string_equals_lc(value.ptr, len, ZEND_STRL("disabled"))) {
        automated_user_events_tracking = NOT_ENABLED;
        result = true;
        _mode_cstr = NULL;
    }

    if (result) {
        ZVAL_STR(decoded_value, zend_string_alloc(value.len, persistent));
        char *out = Z_STRVAL_P(decoded_value);
        memcpy(out, value.ptr, value.len);
        out[value.len] = '\0';
    }

    return result;
}

static PHP_FUNCTION(datadog_appsec_testing_add_all_ancillary_tags)
{
    UNUSED(return_value);
    zval *arr;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "a/", &arr) == FAILURE) {
        return;
    }
    _add_all_tags_to_meta(arr);
}

static PHP_FUNCTION(datadog_appsec_testing_add_basic_ancillary_tags)
{
    UNUSED(return_value);
    zval *arr;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "a/", &arr) == FAILURE) {
        return;
    }
    _add_basic_tags_to_meta(arr);
}

// clang-format off
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(add_ancillary_tags, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(1, "dest", IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(track_user_login_success_event_arginfo, 0, 0, IS_VOID, 3)
ZEND_ARG_INFO(0, user_id)
ZEND_ARG_INFO(0, metadata)
ZEND_ARG_INFO(0, automated)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(datadog_appsec_track_user_signup_event_arginfo, 0, 0, IS_VOID, 3)
ZEND_ARG_INFO(0, user_id)
ZEND_ARG_INFO(0, metadata)
ZEND_ARG_INFO(0, automated)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(track_user_login_failure_event_arginfo, 0, 0, IS_VOID, 4)
ZEND_ARG_INFO(0, user_id)
ZEND_ARG_INFO(0, exists)
ZEND_ARG_INFO(0, metadata)
ZEND_ARG_INFO(0, automated)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(track_custom_event_arginfo, 0, 0, IS_VOID, 2)
ZEND_ARG_INFO(0, event_name)
ZEND_ARG_INFO(0, metadata)
ZEND_END_ARG_INFO()

static const zend_function_entry functions[] = {
    ZEND_RAW_FENTRY(DD_APPSEC_NS "track_user_signup_event", PHP_FN(datadog_appsec_track_user_signup_event), datadog_appsec_track_user_signup_event_arginfo, 0)
    ZEND_RAW_FENTRY(DD_APPSEC_NS "track_user_login_success_event", PHP_FN(datadog_appsec_track_user_login_success_event), track_user_login_success_event_arginfo, 0)
    ZEND_RAW_FENTRY(DD_APPSEC_NS "track_user_login_failure_event", PHP_FN(datadog_appsec_track_user_login_failure_event), track_user_login_failure_event_arginfo, 0)
    ZEND_RAW_FENTRY(DD_APPSEC_NS "track_custom_event", PHP_FN(datadog_appsec_track_custom_event), track_custom_event_arginfo, 0)
    PHP_FE_END
};

static const zend_function_entry test_functions[] = {
    ZEND_RAW_FENTRY(DD_TESTING_NS "add_all_ancillary_tags", PHP_FN(datadog_appsec_testing_add_all_ancillary_tags), add_ancillary_tags, 0)
    ZEND_RAW_FENTRY(DD_TESTING_NS "add_basic_ancillary_tags", PHP_FN(datadog_appsec_testing_add_basic_ancillary_tags), add_ancillary_tags, 0)
    PHP_FE_END
};
// clang-format on

static void _register_functions() { dd_phpobj_reg_funcs(functions); }
static void _register_test_functions() { dd_phpobj_reg_funcs(test_functions); }
