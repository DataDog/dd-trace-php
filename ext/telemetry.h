#ifndef DDTRACE_TELEMETRY_H
#define DDTRACE_TELEMETRY_H

#include <components-rs/telemetry.h>
#include <php.h>

#include "components-rs/common.h"
#include "ddtrace_export.h"
#include "span.h"

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

ddog_SidecarActionsBuffer *ddtrace_telemetry_buffer(void);
void ddtrace_telemetry_first_init(void);
void ddtrace_telemetry_rinit(void);
void ddtrace_telemetry_rshutdown(void);
ddog_TelemetryWorkerHandle *ddtrace_build_telemetry_handle(void);
void ddtrace_telemetry_notify_integration(const char *name, size_t name_len);
void ddtrace_telemetry_finalize(void);
void ddtrace_telemetry_register_services(ddog_SidecarTransport *sidecar);
void ddtrace_telemetry_inc_spans_created(ddtrace_span_data *span);
void ddtrace_telemetry_send_trace_api_metrics(trace_api_metrics metrics);

// public API
DDTRACE_PUBLIC void ddtrace_metric_register_buffer(zend_string *name, ddog_MetricType type, ddog_MetricNamespace ns);
DDTRACE_PUBLIC bool ddtrace_metric_add_point(zend_string *name, double value, zend_string *tags);

#endif // DDTRACE_TELEMETRY_H
