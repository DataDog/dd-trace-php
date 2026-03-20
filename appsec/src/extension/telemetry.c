#include "telemetry.h"
#include "configuration.h"
#include "ddtrace.h"
#include "helper_process.h"
#include "logging.h"
#include "php_compat.h"
#include "string_helpers.h"
#include <stdatomic.h>

#define DD_SDK_EVENT "sdk.event"
static zend_string *_dd_sdk_event_zstr;

static zend_string *_dd_helper_conn_error_zstr;
static zend_string *_dd_helper_conn_success_zstr;
static zend_string *_dd_helper_conn_close_zstr;

void dd_telemetry_startup(void)
{
    _dd_sdk_event_zstr = zend_string_init_interned(LSTRARG(DD_SDK_EVENT), 1);
    _dd_helper_conn_error_zstr =
        zend_string_init_interned(LSTRARG("helper.connection_error"), 1);
    _dd_helper_conn_success_zstr =
        zend_string_init_interned(LSTRARG("helper.connection_success"), 1);
    _dd_helper_conn_close_zstr =
        zend_string_init_interned(LSTRARG("helper.connection_close"), 1);
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

static void _add_helper_conn_metric(zend_string *nonnull name_zstr)
{
    if (get_global_DD_APPSEC_TESTING() &&
        !get_global_DD_APPSEC_TESTING_HELPER_METRICS()) {
        return;
    }
    zend_string *runtime_path = get_DD_APPSEC_HELPER_RUNTIME_PATH();
    char *tags = NULL;
    if (dd_helper_is_rust()) {
        spprintf(&tags, 0, "runtime_path:%s,helper_runtime:rust",
            ZSTR_VAL(runtime_path));
    } else {
        spprintf(&tags, 0, "runtime_path:%s", ZSTR_VAL(runtime_path));
    }
    size_t tags_len = strlen(tags);
    zend_string *tags_zstr = zend_string_init(tags, tags_len, 0);
    dd_telemetry_add_metric(name_zstr, 1, tags_zstr, DDTRACE_METRIC_TYPE_COUNT);
    zend_string_release(tags_zstr);
    efree(tags);
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
