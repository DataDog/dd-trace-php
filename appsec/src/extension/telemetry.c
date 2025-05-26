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

void dd_add_telemetry_metric(const char *nonnull name, size_t name_len,
    double value, const char *nonnull tags_str, size_t tags_len, ddtrace_metric_type type)
{
    if (!ddtrace_metric_register_buffer) {
        mlog_g(dd_log_debug, "ddtrace_metric_register_buffer unavailable");
        return true;
    }
    

    static zend_string *_Atomic name_zstr;
    _init_zstr(&name_zstr, name, name_len);
    zend_string *tags_zstr = zend_string_init(tags_str, tags_len, 1);
    ddtrace_metric_register_buffer(
        name_zstr, type, DDTRACE_METRIC_NAMESPACE_APPSEC);
    ddtrace_metric_add_point(name_zstr, value, tags_zstr);
    zend_string_release(tags_zstr);
    mlog_g(dd_log_debug,
        "Telemetry metric %.*s added with tags %.*s and value %f", (int)name_len,
        name, (int)tags_len, tags_str, value);
}
