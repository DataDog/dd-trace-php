#ifndef DDTRACE_OTEL_CONFIG_H
#define DDTRACE_OTEL_CONFIG_H

#include <env/env.h>

bool ddtrace_conf_otel_propagators(zai_env_buffer *buf, bool pre_rinit);
bool ddtrace_conf_otel_sample_rate(zai_env_buffer *buf, bool pre_rinit);
bool ddtrace_conf_otel_traces_exporter(zai_env_buffer *buf, bool pre_rinit);
bool ddtrace_conf_otel_metrics_exporter(zai_env_buffer *buf, bool pre_rinit);

#endif // DDTRACE_OTEL_CONFIG_H
