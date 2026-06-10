#include "otel_config.h"
#include <env/env.h>
#include "datadog.h"
#include <components-rs/common.h>
#include <components/log/log.h>
#include <ext/sidecar.h>
#include <ext/telemetry.h>
#include "configuration.h"

ZEND_EXTERN_MODULE_GLOBALS(datadog);

void datadog_report_otel_cfg_telemetry_invalid(const char *otel_cfg, const char *dd_cfg, bool pre_rinit) {
    if (!pre_rinit && DATADOG_G(sidecar) && get_DD_INSTRUMENTATION_TELEMETRY_ENABLED()) {
        ddog_sidecar_telemetry_register_metric(&DATADOG_G(sidecar), DDOG_CHARSLICE_C("otel.env.invalid"), DDOG_METRIC_TYPE_COUNT, DDOG_METRIC_NAMESPACE_TRACERS);
        ddog_SidecarActionsBuffer *buffer = datadog_telemetry_buffer();
        ddog_CharSlice tags;
        tags.len = asprintf((char **)&tags.ptr, "config_opentelemetry:%s,config_datadog:%s", otel_cfg, dd_cfg);
        ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("otel.env.invalid"), 1, tags);
        free((char *)tags.ptr);
    }
}

bool datadog_get_otel_value(zai_str str, zai_env_buffer *buf, bool pre_rinit) {
    if (!pre_rinit && zai_sapi_getenv(str, buf) == ZAI_ENV_SUCCESS) return true;
    zai_option_str sys = zai_sys_getenv(str);
    if (zai_option_str_is_some(sys)) { buf->ptr = (char *) sys.ptr; buf->len = sys.len; return true; }

    zval *cfg = cfg_get_entry(str.ptr, str.len);
    if (cfg) {
        if (Z_TYPE_P(cfg) == IS_ARRAY) {
            zval *val;
            char *off = buf->ptr;
            ZEND_HASH_FOREACH_VAL(Z_ARR_P(cfg), val) {
                if (Z_TYPE_P(val) == IS_STRING) {
                    if (off - buf->ptr + Z_STRLEN_P(val) + 2 >= ZAI_ENV_MAX_BUFSIZ) {
                        return false;
                    }
                    if (off != buf->ptr) {
                        *off++ = ',';
                    }
                    memcpy(off, Z_STRVAL_P(val), Z_STRLEN_P(val));
                    off += Z_STRLEN_P(val);
                }
            } ZEND_HASH_FOREACH_END();
            *off = 0;
        } else if (Z_STRLEN_P(cfg) == 0 || Z_STRLEN_P(cfg) + 1 >= ZAI_ENV_MAX_BUFSIZ) {
            return false;
        } else {
            memcpy(buf->ptr, Z_STRVAL_P(cfg), Z_STRLEN_P(cfg) + 1);
        }
        return true;
    }

    return false;
}

static bool ddtrace_conf_otel_resource_attributes_special(const char *tag, int len, zai_env_buffer *buf, bool pre_rinit) {
    ZAI_ENV_BUFFER_INIT(local, ZAI_ENV_MAX_BUFSIZ);
    if (!datadog_get_otel_value((zai_str)ZAI_STRL("OTEL_RESOURCE_ATTRIBUTES"), &local, pre_rinit)) {
        return false;
    }

    for (char *cur = local.ptr, *key_start = cur; *cur; ++cur) {
        if (*cur == '=') {
            char *key_end = cur++;
            while (*cur && *cur != ',') {
                ++cur;
            }
            if (key_end - key_start == len && memcmp(key_start, tag, len) == 0 && key_end[1]) {
                size_t vallen = cur - (key_end + 1);
                memcpy(buf->ptr, key_end + 1, vallen);
                buf->ptr[vallen] = 0;
                return true;
            }
            key_start = cur-- + 1;
        }
    }

    return false;
}

bool ddtrace_conf_otel_resource_attributes_env(zai_env_buffer *buf, bool pre_rinit) {
    return ddtrace_conf_otel_resource_attributes_special(ZEND_STRL("deployment.environment"), buf, pre_rinit);
}

bool ddtrace_conf_otel_resource_attributes_version(zai_env_buffer *buf, bool pre_rinit) {
    return ddtrace_conf_otel_resource_attributes_special(ZEND_STRL("service.version"), buf, pre_rinit);
}

bool ddtrace_conf_otel_service_name(zai_env_buffer *buf, bool pre_rinit) {
    return datadog_get_otel_value((zai_str)ZAI_STRL("OTEL_SERVICE_NAME"), buf, pre_rinit)
        || ddtrace_conf_otel_resource_attributes_special(ZEND_STRL("service.name"), buf, pre_rinit);
}

bool ddtrace_conf_otel_log_level(zai_env_buffer *buf, bool pre_rinit) {
    return datadog_get_otel_value((zai_str)ZAI_STRL("OTEL_LOG_LEVEL"), buf, pre_rinit);
}

bool ddtrace_conf_otel_resource_attributes_tags(zai_env_buffer *buf, bool pre_rinit) {
    ZAI_ENV_BUFFER_INIT(local, ZAI_ENV_MAX_BUFSIZ);
    if (!datadog_get_otel_value((zai_str)ZAI_STRL("OTEL_RESOURCE_ATTRIBUTES"), &local, pre_rinit)) {
        return false;
    }

    char *out = buf->ptr;
    int tags = 0;
    for (char *cur = local.ptr, *key_start = cur; *cur; ++cur) {
        if (*cur == '=') {
            char *key = key_start, *key_end = cur++;
            while (*cur && *cur != ',') {
                ++cur;
            }
            key_start = cur + 1;
            if (key_end - key == strlen("deployment.environment") && memcmp(key, ZEND_STRL("deployment.environment")) == 0) {
                continue;
            }
            if (key_end - key == strlen("service.name") && memcmp(key, ZEND_STRL("service.name")) == 0) {
                continue;
            }
            if (key_end - key == strlen("service.version") && memcmp(key, ZEND_STRL("service.version")) == 0) {
                continue;
            }
            memmove(out, key, cur - key);
            out[key_end - key] = ':';
            out += cur - key;
            *out++ = ',';
            if (++tags == 10 && *cur) {
                LOG_ONCE(WARN, "OTEL_RESOURCE_ATTRIBUTES has more than 10 tags, ignoring all of: %s", cur + 1);
                break;
            }
            --cur;
        }
    }
    if (out != buf->ptr) {
        --out;
    }
    *out = 0;

    return true;
}

// Reads OTEL_EXPORTER_OTLP_ENDPOINT, strips trailing slashes, and appends the
// signal-specific suffix (e.g. "/v1/metrics" or "/v1/traces"). Used as the
// fallback for the per-signal OTLP endpoint configs.
static bool ddtrace_conf_otel_otlp_endpoint_with_suffix(zai_env_buffer *buf, bool pre_rinit, const char *suffix, size_t suffix_len) {
    ZAI_ENV_BUFFER_INIT(local, ZAI_ENV_MAX_BUFSIZ);
    if (!datadog_get_otel_value((zai_str)ZAI_STRL("OTEL_EXPORTER_OTLP_ENDPOINT"), &local, pre_rinit) || !local.ptr[0]) {
        return false;
    }

    size_t base_len = strlen(local.ptr);
    while (base_len > 0 && local.ptr[base_len - 1] == '/') {
        base_len--;
    }

    if (base_len + suffix_len + 1 > ZAI_ENV_MAX_BUFSIZ) {
        return false;
    }

    memcpy(buf->ptr, local.ptr, base_len);
    memcpy(buf->ptr + base_len, suffix, suffix_len + 1);
    return true;
}

bool ddtrace_conf_otel_otlp_endpoint(zai_env_buffer *buf, bool pre_rinit) {
    return ddtrace_conf_otel_otlp_endpoint_with_suffix(buf, pre_rinit, ZEND_STRL("/v1/metrics"));
}

bool ddtrace_conf_otel_traces_otlp_endpoint(zai_env_buffer *buf, bool pre_rinit) {
    return ddtrace_conf_otel_otlp_endpoint_with_suffix(buf, pre_rinit, ZEND_STRL("/v1/traces"));
}

bool ddtrace_conf_otel_traces_otlp_enabled(zai_env_buffer *buf, bool pre_rinit) {
    if (!datadog_get_otel_value((zai_str)ZAI_STRL("OTEL_TRACES_EXPORTER"), buf, pre_rinit)) {
        return false;
    }
    if (strcmp(buf->ptr, "otlp") == 0) {
        // Gate: pinning the Datadog agent trace protocol via
        // DD_TRACE_AGENT_PROTOCOL_VERSION is incompatible with routing traces
        // over OTLP. When it is set, OTLP trace export is disabled and a notice
        // is logged (the agent trace protocol version takes precedence).
        zai_option_str protocol_version = zai_sys_getenv((zai_str)ZAI_STRL("DD_TRACE_AGENT_PROTOCOL_VERSION"));
        if (zai_option_str_is_some(protocol_version) && protocol_version.len > 0) {
            LOG_ONCE(WARN, "OTLP trace export requested via OTEL_TRACES_EXPORTER=otlp, but "
                           "DD_TRACE_AGENT_PROTOCOL_VERSION is set; OTLP trace export disabled "
                           "(the agent trace protocol version takes precedence)");
            buf->ptr = "0"; buf->len = 1;
            return true;
        }
        buf->ptr = "1"; buf->len = 1;
        return true;
    }
    // Any other value (including "none") leaves OTLP trace export disabled.
    buf->ptr = "0"; buf->len = 1;
    return true;
}

bool ddtrace_conf_otel_traces_otlp_headers(zai_env_buffer *buf, bool pre_rinit) {
    // OTEL_EXPORTER_OTLP_TRACES_HEADERS falls back to OTEL_EXPORTER_OTLP_HEADERS.
    // The value is a comma-separated list of key=value pairs, passed through as-is.
    return datadog_get_otel_value((zai_str)ZAI_STRL("OTEL_EXPORTER_OTLP_HEADERS"), buf, pre_rinit);
}

bool ddtrace_conf_otel_traces_otlp_timeout(zai_env_buffer *buf, bool pre_rinit) {
    // OTEL_EXPORTER_OTLP_TRACES_TIMEOUT falls back to OTEL_EXPORTER_OTLP_TIMEOUT (milliseconds).
    return datadog_get_otel_value((zai_str)ZAI_STRL("OTEL_EXPORTER_OTLP_TIMEOUT"), buf, pre_rinit);
}

bool ddtrace_conf_otel_traces_otlp_protocol(zai_env_buffer *buf, bool pre_rinit) {
    // OTEL_EXPORTER_OTLP_TRACES_PROTOCOL falls back to OTEL_EXPORTER_OTLP_PROTOCOL.
    if (!datadog_get_otel_value((zai_str)ZAI_STRL("OTEL_EXPORTER_OTLP_PROTOCOL"), buf, pre_rinit)) {
        return false;
    }
    // Only http/json is honored for OTLP trace export today. Report any other
    // value but keep it visible so config telemetry reflects the user's setting.
    if (strcmp(buf->ptr, "http/json") != 0) {
        LOG_ONCE(WARN, "OTEL_EXPORTER_OTLP_TRACES_PROTOCOL '%s' is not supported for OTLP trace export; only http/json is honored", buf->ptr);
    }
    return true;
}
