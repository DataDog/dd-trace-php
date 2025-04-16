#ifndef DD_OTEL_CONFIG_H
#define DD_OTEL_CONFIG_H

#include <env/env.h>

bool ddtrace_conf_otel_resource_attributes_env(zai_env_buffer buf, bool pre_rinit);
bool ddtrace_conf_otel_resource_attributes_version(zai_env_buffer buf, bool pre_rinit);
bool ddtrace_conf_otel_service_name(zai_env_buffer buf, bool pre_rinit);
bool ddtrace_conf_otel_log_level(zai_env_buffer buf, bool pre_rinit);
bool ddtrace_conf_otel_propagators(zai_env_buffer buf, bool pre_rinit);
bool ddtrace_conf_otel_sample_rate(zai_env_buffer buf, bool pre_rinit);
bool ddtrace_conf_otel_traces_exporter(zai_env_buffer buf, bool pre_rinit);
bool ddtrace_conf_otel_metrics_exporter(zai_env_buffer buf, bool pre_rinit);
bool ddtrace_conf_otel_resource_attributes_tags(zai_env_buffer buf, bool pre_rinit);

#endif // DD_OTEL_CONFIG_H
