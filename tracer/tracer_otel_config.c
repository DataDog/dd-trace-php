#include "tracer_otel_config.h"
#include <env/env.h>
#include <ext/otel_config.h>
#include "ddtrace.h"
#include <components/log/log.h>
#include <ext/sidecar.h>
#include <ext/telemetry.h>
#include "configuration.h"

ZEND_EXTERN_MODULE_GLOBALS(datadog);

bool ddtrace_conf_otel_propagators(zai_env_buffer *buf, bool pre_rinit) {
    ZAI_ENV_BUFFER_INIT(local, ZAI_ENV_MAX_BUFSIZ);
    if (!datadog_get_otel_value((zai_str)ZAI_STRL("OTEL_PROPAGATORS"), &local, pre_rinit)) {
        return false;
    }
    memcpy(buf->ptr, local.ptr, strlen(local.ptr) + 1);
    char *off = (char *)zend_memnstr(buf->ptr, ZEND_STRL("b3"), buf->ptr + strlen(buf->ptr));
    if (off && (!off[strlen("b3")] || off[strlen("b3")] == ',') && strlen(buf->ptr) < buf->len - 100) {
        memmove(off + strlen("b3 single header"), off + strlen("b3"), buf->ptr + strlen(buf->ptr) - (off + strlen("b3")) + 1);
        memcpy(off, "b3 single header", strlen("b3 single header"));
    }
    return true;
}

bool ddtrace_conf_otel_sample_rate(zai_env_buffer *buf, bool pre_rinit) {
    if (!datadog_get_otel_value((zai_str)ZAI_STRL("OTEL_TRACES_SAMPLER"), buf, pre_rinit)) {
        return false;
    }

    if (strcmp(buf->ptr, "always_on") == 0 || strcmp(buf->ptr, "parentbased_always_on") == 0) {
        buf->ptr = "1"; buf->len = 1;
        return true;
    }
    if (strcmp(buf->ptr, "always_off") == 0 || strcmp(buf->ptr, "parentbased_always_off") == 0) {
        buf->ptr = "0"; buf->len = 1;
        return true;
    }
    if (strcmp(buf->ptr, "traceidratio") == 0 || strcmp(buf->ptr, "parentbased_traceidratio") == 0) {
        if (datadog_get_otel_value((zai_str)ZAI_STRL("OTEL_TRACES_SAMPLER_ARG"), buf, pre_rinit)) {
            return true;
        }
        LOG_ONCE(WARN, "OTEL_TRACES_SAMPLER is %s, but is missing OTEL_TRACES_SAMPLER_ARG", buf->ptr);
    } else {
        LOG_ONCE(WARN, "OTEL_TRACES_SAMPLER has invalid value: %s", buf->ptr);
    }
    datadog_report_otel_cfg_telemetry_invalid("otel_traces_sampler", "dd_trace_sample_rate", pre_rinit);
    return false;
}

bool ddtrace_conf_otel_traces_exporter(zai_env_buffer *buf, bool pre_rinit) {
    if (datadog_get_otel_value((zai_str)ZAI_STRL("OTEL_TRACES_EXPORTER"), buf, pre_rinit)) {
        if (strcmp(buf->ptr, "none") == 0) {
            buf->ptr = "0"; buf->len = 1;
            return true;
        }
        LOG_ONCE(WARN, "OTEL_TRACES_EXPORTER has invalid value: %s", buf->ptr);
        datadog_report_otel_cfg_telemetry_invalid("otel_traces_exporter", "dd_trace_enabled", pre_rinit);
    }
    return false;
}

bool ddtrace_conf_otel_metrics_exporter(zai_env_buffer *buf, bool pre_rinit) {
    if (datadog_get_otel_value((zai_str)ZAI_STRL("OTEL_METRICS_EXPORTER"), buf, pre_rinit)) {
        if (strcmp(buf->ptr, "none") == 0) {
            buf->ptr = "0"; buf->len = 1;
            return true;
        }
        LOG_ONCE(WARN, "OTEL_METRICS_EXPORTER has invalid value: %s", buf->ptr);
        datadog_report_otel_cfg_telemetry_invalid("otel_metrics_exporter", "dd_integration_metrics_enabled", pre_rinit);
    }
    return false;
}
