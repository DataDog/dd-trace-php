#ifndef DDTRACE_TELEMETRY_H
#define DDTRACE_TELEMETRY_H

#include <components/rust/telemetry.h>

void ddtrace_telemetry_setup(void);
void ddtrace_telemetry_shutdown(void);
ddog_TelemetryWorkerHandle *ddtrace_build_telemetry_handle(void);
void ddtrace_telemetry_notify_integration(const char *name, size_t name_len);
void ddtrace_telemetry_finalize(void);

#endif // DDTRACE_TELEMETRY_H
