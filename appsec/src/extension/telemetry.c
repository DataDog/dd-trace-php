#include "telemetry.h"
#include "ddtrace.h"
#include "logging.h"
#include "string_helpers.h"

void dd_add_telemetry_metric(const char *nonnull name, size_t name_len,
    double value, const char *nonnull tags_str, size_t tags_len,
    ddtrace_metric_type type)
{
    zend_string *name_zstr = zend_string_init(name, name_len, 1);
    zend_string *tags_zstr = zend_string_init(tags_str, tags_len, 1);
    ddtrace_metric_register_buffer(
        name_zstr, type, DDTRACE_METRIC_NAMESPACE_APPSEC);
    ddtrace_metric_add_point(name_zstr, value, tags_zstr);
    zend_string_release(tags_zstr);
    zend_string_release(name_zstr);
    mlog_g(dd_log_debug,
        "Telemetry metric %.*s added with tags %.*s and value %f",
        (int)name_len, name, (int)tags_len, tags_str, value);
}
