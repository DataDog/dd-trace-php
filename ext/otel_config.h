#ifndef DD_OTEL_CONFIG_H
#define DD_OTEL_CONFIG_H

#include <env/env.h>
#include <components-rs/common.h>
#include <zend_string.h>

void datadog_report_otel_cfg_telemetry_invalid(const char *otel_cfg, const char *dd_cfg, bool pre_rinit);
bool datadog_get_otel_value(zai_str str, zai_env_buffer *buf, bool pre_rinit);

bool ddtrace_conf_otel_resource_attributes_env(zai_env_buffer *buf, bool pre_rinit);
bool ddtrace_conf_otel_resource_attributes_version(zai_env_buffer *buf, bool pre_rinit);
bool ddtrace_conf_otel_service_name(zai_env_buffer *buf, bool pre_rinit);
bool ddtrace_conf_otel_log_level(zai_env_buffer *buf, bool pre_rinit);
bool ddtrace_conf_otel_resource_attributes_tags(zai_env_buffer *buf, bool pre_rinit);
ddog_Endpoint *datadog_otel_metrics_endpoint(bool pre_rinit);

#endif // DD_OTEL_CONFIG_H
