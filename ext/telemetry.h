#ifndef DDTRACE_TELEMETRY_H
#define DDTRACE_TELEMETRY_H

#include <components/rust/common.h>

ddog_TelemetryWorkerHandle *ddtrace_build_telemetry_handle(void);
void ddtrace_setup_composer_telemetry_hook(void);

#endif // DDTRACE_TELEMETRY_H
