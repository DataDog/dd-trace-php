#ifndef COMPONENT_TELEMETRY_H
#define COMPONENT_TELEMETRY_H

void ddog_integration_error_telemetryf(const char *format, ...);
const char* ddog_telemetry_redact_file(const char* file);

#endif // COMPONENT_TELEMETRY_H