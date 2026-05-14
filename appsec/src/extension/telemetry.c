#include "telemetry.h"
#include "configuration.h"
#include "ddtrace.h"
#include "logging.h"
#include "php_compat.h"
#include "php_helpers.h"
#include "string_helpers.h"

#define DD_SDK_EVENT "sdk.event"
#define DD_MISSING_USER_LOGIN "appsec.instrum.user_auth.missing_user_login"
#define DD_MISSING_USER_ID "appsec.instrum.user_auth.missing_user_id"
static zend_string *_dd_sdk_event_zstr;
static zend_string *_dd_missing_user_login_zstr;
static zend_string *_dd_missing_user_id_zstr;

static zend_string *_dd_helper_conn_error_zstr;
static zend_string *_dd_helper_conn_success_zstr;
static zend_string *_dd_helper_conn_close_zstr;

static zend_string *_waf_duration_ext_tel_zstr;
static zend_string *_rasp_duration_ext_tel_zstr;

static THREAD_LOCAL_ON_ZTS zend_string *nullable _cached_waf_version;
static THREAD_LOCAL_ON_ZTS zend_string *nullable _cached_event_rules_version;

static zend_string *nullable _duration_ext_tags_from_cache(void);
static void _release_zstr(zend_string *nullable *nonnull slot);
static void _cache_replace(zend_string *nullable *nonnull slot,
    const char *nonnull val, size_t val_len);

void dd_telemetry_startup(void)
{
    _dd_sdk_event_zstr = zend_string_init_interned(LSTRARG(DD_SDK_EVENT), 1);
    _dd_missing_user_login_zstr =
        zend_string_init_interned(LSTRARG(DD_MISSING_USER_LOGIN), 1);
    _dd_missing_user_id_zstr =
        zend_string_init_interned(LSTRARG(DD_MISSING_USER_ID), 1);
    _dd_helper_conn_error_zstr =
        zend_string_init_interned(LSTRARG("helper.connection_error"), 1);
    _dd_helper_conn_success_zstr =
        zend_string_init_interned(LSTRARG("helper.connection_success"), 1);
    _dd_helper_conn_close_zstr =
        zend_string_init_interned(LSTRARG("helper.connection_close"), 1);
    _waf_duration_ext_tel_zstr =
        zend_string_init_interned(LSTRARG("waf.duration_ext"), 1);
    _rasp_duration_ext_tel_zstr =
        zend_string_init_interned(LSTRARG("rasp.duration_ext"), 1);
}

void dd_telemetry_tshutdown(void)
{
    _release_zstr(&_cached_waf_version);
    _release_zstr(&_cached_event_rules_version);
}

void dd_telemetry_rinit(void)
{
    _release_zstr(&_cached_event_rules_version);
    _release_zstr(&_cached_waf_version);
}

void dd_telemetry_note_helper_string_meta(const char *nonnull key,
    size_t key_len, const char *nonnull val, size_t val_len)
{
    if (dd_string_equals_lc(key, key_len, LSTRARG("_dd.appsec.waf.version"))) {
        _cache_replace(&_cached_waf_version, val, val_len);
    } else if (dd_string_equals_lc(
                   key, key_len, LSTRARG("_dd.appsec.event_rules.version"))) {
        _cache_replace(&_cached_event_rules_version, val, val_len);
    }
}

void dd_telemetry_add_metric(zend_string *nonnull name_zstr, double value,
    zend_string *nonnull tags_zstr, ddtrace_metric_type type)
{
    ddtrace_metric_register_buffer(
        name_zstr, type, DDTRACE_METRIC_NAMESPACE_APPSEC);
    ddtrace_metric_add_point(name_zstr, value, tags_zstr);
    mlog_g(dd_log_debug,
        "Telemetry metric %.*s added with tags %.*s and value %f",
        (int)ZSTR_LEN(name_zstr), ZSTR_VAL(name_zstr), (int)ZSTR_LEN(tags_zstr),
        ZSTR_VAL(tags_zstr), value);
}

void dd_telemetry_add_sdk_event(char *nonnull event_type, size_t event_type_len)
{
    char *tags = NULL;
    size_t tags_len = asprintf(&tags, "event_type:%.*s,sdk_version:v2",
        (int)event_type_len, event_type);
    zend_string *tags_zstr = zend_string_init(tags, tags_len, 1);
    dd_telemetry_add_metric(
        _dd_sdk_event_zstr, 1, tags_zstr, DDTRACE_METRIC_TYPE_COUNT);
    zend_string_release(tags_zstr);

    free(tags);
}

static void _add_user_auth_metric(zend_string *nonnull name_zstr,
    const char *nonnull event_type, size_t event_type_len,
    const char *nonnull framework, size_t framework_len)
{
    char *tags = NULL;
    size_t tags_len = asprintf(&tags, "framework:%.*s,event_type:%.*s",
        (int)framework_len, framework, (int)event_type_len, event_type);
    zend_string *tags_zstr = zend_string_init(tags, tags_len, 1);
    dd_telemetry_add_metric(name_zstr, 1, tags_zstr, DDTRACE_METRIC_TYPE_COUNT);
    zend_string_release(tags_zstr);
    free(tags);
}

void dd_telemetry_add_missing_user_login(const char *nonnull event_type,
    size_t event_type_len, const char *nonnull framework, size_t framework_len)
{
    _add_user_auth_metric(_dd_missing_user_login_zstr, event_type,
        event_type_len, framework, framework_len);
}

void dd_telemetry_add_missing_user_id(const char *nonnull event_type,
    size_t event_type_len, const char *nonnull framework, size_t framework_len)
{
    _add_user_auth_metric(_dd_missing_user_id_zstr, event_type, event_type_len,
        framework, framework_len);
}

static void _add_helper_conn_metric(zend_string *nonnull name_zstr)
{
    if (get_global_DD_APPSEC_TESTING() &&
        !get_global_DD_APPSEC_TESTING_HELPER_METRICS()) {
        return;
    }
    zend_string *tags_zstr =
        zend_string_init(ZEND_STRL("helper_runtime:rust"), 0);
    dd_telemetry_add_metric(name_zstr, 1, tags_zstr, DDTRACE_METRIC_TYPE_COUNT);
    zend_string_release(tags_zstr);
}

void dd_telemetry_helper_conn_error(void)
{
    _add_helper_conn_metric(_dd_helper_conn_error_zstr);
}

void dd_telemetry_helper_conn_success(void)
{
    _add_helper_conn_metric(_dd_helper_conn_success_zstr);
}

void dd_telemetry_helper_conn_close(void)
{
    _add_helper_conn_metric(_dd_helper_conn_close_zstr);
}

// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
void dd_telemetry_submit_duration_ext(double waf_ext_us, double rasp_ext_us)
{
    if (!dd_trace_loaded() || ddtrace_metric_register_buffer == NULL ||
        ddtrace_metric_add_point == NULL) {
        return;
    }

    zend_string *tags_zstr = _duration_ext_tags_from_cache();
    if (!tags_zstr) {
        return;
    }

    if (waf_ext_us > 0.0) {
        ddtrace_metric_register_buffer(_waf_duration_ext_tel_zstr,
            DDTRACE_METRIC_TYPE_DISTRIBUTION, DDTRACE_METRIC_NAMESPACE_APPSEC);
        ddtrace_metric_add_point(
            _waf_duration_ext_tel_zstr, waf_ext_us, tags_zstr);
    }

    if (rasp_ext_us > 0.0) {
        ddtrace_metric_register_buffer(_rasp_duration_ext_tel_zstr,
            DDTRACE_METRIC_TYPE_DISTRIBUTION, DDTRACE_METRIC_NAMESPACE_APPSEC);
        ddtrace_metric_add_point(
            _rasp_duration_ext_tel_zstr, rasp_ext_us, tags_zstr);
    }

    zend_string_release(tags_zstr);
}

static zend_string *nullable _duration_ext_tags_from_cache(void)
{
    const char *waf_version =
        _cached_waf_version ? ZSTR_VAL(_cached_waf_version) : "unknown";
    const char *rules_version = _cached_event_rules_version
                                    ? ZSTR_VAL(_cached_event_rules_version)
                                    : "unknown";

    char *tags = NULL;
    int tags_len = spprintf(&tags, 0, "waf_version:%s,event_rules_version:%s",
        waf_version, rules_version);
    if (tags_len < 0 || !tags) {
        return NULL;
    }
    zend_string *tags_zstr = zend_string_init(tags, (size_t)tags_len, 0);
    efree(tags);
    return tags_zstr;
}

static void _release_zstr(zend_string *nullable *nonnull slot)
{
    if (*slot) {
        zend_string_release(*slot);
        *slot = NULL;
    }
}

static void _cache_replace(zend_string *nullable *nonnull slot,
    const char *nonnull val, size_t val_len)
{
    _release_zstr(slot);
    *slot = zend_string_init(val, val_len, 1 /* persistent */);
}
