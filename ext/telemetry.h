#ifndef DATADOG_TELEMETRY_H
#define DATADOG_TELEMETRY_H

#include <components-rs/telemetry.h>
#include <php.h>

#include "components-rs/common.h"
#include "datadog_export.h"

void ddtrace_integration_error_telemetryf(ddog_Log source, const char *format, ...);
#define INTEGRATION_ERROR_TELEMETRY(source, format, ...) { ddtrace_integration_error_telemetryf(DDOG_LOG_##source, format, ##__VA_ARGS__); }

typedef struct _trace_api_metrics {
    int requests;
    int responses_1xx;
    int responses_2xx;
    int responses_3xx;
    int responses_4xx;
    int responses_5xx;
    int errors_timeout;
    int errors_network;
    int errors_status_code;
} trace_api_metrics;

ddog_SidecarActionsBuffer *datadog_telemetry_buffer(void);
ddog_ShmCacheMap *datadog_telemetry_cache(void);
const char *ddtrace_telemetry_redact_file(const char *file);
void datadog_telemetry_rinit(void);
void datadog_telemetry_rshutdown(void);
void datadog_telemetry_finalize(void);
void datadog_telemetry_lifecycle_end(void);
void datadog_telemetry_register_services(ddog_SidecarTransport **sidecar);

// public API
DATADOG_PUBLIC void datadog_metric_register_buffer(zend_string *name, ddog_MetricType type, ddog_MetricNamespace ns);
DATADOG_PUBLIC bool datadog_metric_add_point(zend_string *name, double value, zend_string *tags);

DATADOG_PUBLIC extern bool datadog_loaded_by_ssi;

#endif // DDTRACE_TELEMETRY_H
