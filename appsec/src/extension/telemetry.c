#include "telemetry.h"
#include "ddtrace.h"
#include "logging.h"
#include "string_helpers.h"
#include <stdatomic.h>

static void _init_zstr(
    zend_string *_Atomic *nonnull zstr, const char *nonnull str, size_t len)
{
    zend_string *zstr_cur = atomic_load_explicit(zstr, memory_order_acquire);
    if (zstr_cur != NULL) {
        return;
    }
    zend_string *zstr_new = zend_string_init(str, len, 1);
    if (atomic_compare_exchange_strong_explicit(zstr, &(zend_string *){NULL},
            zstr_new, memory_order_release, memory_order_relaxed)) {
        return;
    }
    zend_string_release(zstr_new);
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
    static zend_string *_Atomic key_zstr;
    _init_zstr(&key_zstr, LSTRARG("sdk.event"));
    char *tags = NULL;
    size_t tags_len = asprintf(&tags, "event_type:%.*s,sdk_version:v2",
        (int)event_type_len, event_type);
    zend_string *tags_zstr = zend_string_init(tags, tags_len, 1);
    dd_telemetry_add_metric(key_zstr, 1, tags_zstr, DDTRACE_METRIC_TYPE_COUNT);
    mlog_g(dd_log_debug,
        "Telemetry metric %.*s added with tags %.*s and value %d",
        (int)ZSTR_LEN(key_zstr), ZSTR_VAL(key_zstr), (int)ZSTR_LEN(tags_zstr),
        ZSTR_VAL(tags_zstr), 1);
    zend_string_release(tags_zstr);

    free(tags);
}
