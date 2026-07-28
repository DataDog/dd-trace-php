#ifndef DD_TRACER_TELEMETRY_H
#define DD_TRACER_TELEMETRY_H

#include <ext/telemetry.h>
#include "ddtrace.h"

void ddtrace_telemetry_first_init(void);
void ddtrace_telemetry_rinit(void);
void ddtrace_telemetry_rshutdown(void);
void ddtrace_integration_error_telemetryf(ddog_Log source, const char *format, ...);
void ddtrace_telemetry_notify_integration(const char *name, size_t name_len);
void ddtrace_telemetry_notify_integration_version(const char *name, size_t name_len, const char *version, size_t version_len);

void ddtrace_telemetry_inc_spans_created(ddtrace_span_data *span);
// Generates the queue id the BGS metrics are reported on.  Call once per process, before any
// request thread runs, and only when the background sender is in use.
void ddtrace_telemetry_bgs_init(void);
// Called by the background sender thread (coms.c) to accumulate metrics atomically.
// Never touches the sidecar; the request thread flushes via ddtrace_telemetry_flush_bgs_metrics_if_due().
void ddtrace_telemetry_send_trace_api_metrics(trace_api_metrics metrics);
// Called from datadog_telemetry_finalize() to flush accumulated BGS metrics through
// the current thread's sidecar connection, at most once per flush interval.
void ddtrace_telemetry_flush_bgs_metrics_if_due(zend_datadog_globals *datadog_globals);
// Force-flush accumulated BGS metrics regardless of the time gate and release the application
// they are reported under. Call once per process from MSHUTDOWN, before the sidecar's instance
// id is dropped.
void ddtrace_telemetry_flush_bgs_metrics_final(void);

#endif // DD_TRACER_TELEMETRY_H
