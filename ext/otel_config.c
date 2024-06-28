#include "otel_config.h"
#include <env/env.h>
#include "ddtrace.h"
#include <components/log/log.h>
#include "sidecar.h"
#include "telemetry.h"
#include "configuration.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static void report_otel_cfg_telemetry_invalid(const char *otel_cfg, const char *dd_cfg, bool pre_rinit) {
    if (!pre_rinit && ddtrace_sidecar && get_DD_INSTRUMENTATION_TELEMETRY_ENABLED()) {
        ddog_SidecarActionsBuffer *buffer = ddtrace_telemetry_buffer();
        ddog_sidecar_telemetry_register_metric_buffer(buffer, DDOG_CHARSLICE_C("tracers.otel.env.invalid"), DDOG_METRIC_TYPE_COUNT,
                                                      DDOG_METRIC_NAMESPACE_TRACERS);
        ddog_CharSlice tags;
        tags.len = asprintf((char **)&tags.ptr, "config.opentelemetry:%s,config.datadog:%s", otel_cfg, dd_cfg);
        ddog_sidecar_telemetry_add_span_metric_point_buffer(buffer, DDOG_CHARSLICE_C("tracers.otel.env.invalid"), 1, tags);
        free((char *)tags.ptr);
    }
}

static bool get_otel_value(zai_str str, zai_env_buffer buf, bool pre_rinit) {
    if (zai_getenv_ex(str, buf, pre_rinit) == ZAI_ENV_SUCCESS) {
        return true;
    }

    zval *cfg = cfg_get_entry(str.ptr, str.len);
    if (cfg) {
        if (Z_TYPE_P(cfg) == IS_ARRAY) {
            zval *val;
            char *off = buf.ptr;
            ZEND_HASH_FOREACH_VAL(Z_ARR_P(cfg), val) {
                if (Z_TYPE_P(val) == IS_STRING) {
                    if (off - buf.ptr + Z_STRLEN_P(val) + 2 >= ZAI_ENV_MAX_BUFSIZ) {
                        return false;
                    }
                    if (off != buf.ptr) {
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
            memcpy(buf.ptr, Z_STRVAL_P(cfg), Z_STRLEN_P(cfg) + 1);
        }
        return true;
    }

    return false;
}

static bool ddtrace_conf_otel_resource_attributes_special(const char *tag, int len, zai_env_buffer buf, bool pre_rinit) {
    if (!get_otel_value((zai_str)ZAI_STRL("OTEL_RESOURCE_ATTRIBUTES"), buf, pre_rinit)) {
        return false;
    }

    for (char *cur = buf.ptr, *key_start = cur; *cur; ++cur) {
        if (*cur == '=') {
            char *key_end = cur++;
            while (*cur && *cur != ',') {
                ++cur;
            }
            if (key_end - key_start == len && memcmp(key_start, tag, len) == 0 && key_end[1]) {
                size_t vallen = cur - (key_end + 1);
                memcpy(buf.ptr, key_end + 1, vallen);
                buf.ptr[vallen] = 0;
                return true;
            }
            key_start = cur-- + 1;
        }
    }

    return false;
}

bool ddtrace_conf_otel_resource_attributes_env(zai_env_buffer buf, bool pre_rinit) {
    return ddtrace_conf_otel_resource_attributes_special(ZEND_STRL("deployment.environment"), buf, pre_rinit);
}

bool ddtrace_conf_otel_resource_attributes_version(zai_env_buffer buf, bool pre_rinit) {
    return ddtrace_conf_otel_resource_attributes_special(ZEND_STRL("service.version"), buf, pre_rinit);
}

bool ddtrace_conf_otel_service_name(zai_env_buffer buf, bool pre_rinit) {
    return get_otel_value((zai_str)ZAI_STRL("OTEL_SERVICE_NAME"), buf, pre_rinit)
        || ddtrace_conf_otel_resource_attributes_special(ZEND_STRL("service.name"), buf, pre_rinit);
}

bool ddtrace_conf_otel_log_level(zai_env_buffer buf, bool pre_rinit) {
    return get_otel_value((zai_str)ZAI_STRL("OTEL_LOG_LEVEL"), buf, pre_rinit);
}

bool ddtrace_conf_otel_propagators(zai_env_buffer buf, bool pre_rinit) {
    if (!get_otel_value((zai_str)ZAI_STRL("OTEL_PROPAGATORS"), buf, pre_rinit)) {
        return false;
    }
    char *off = (char *)zend_memnstr(buf.ptr, ZEND_STRL("b3"), buf.ptr + strlen(buf.ptr));
    if (off && (!off[strlen("b3")] || off[strlen("b3")] == ',') && strlen(buf.ptr) < buf.len - 100) {
        memmove(off + strlen("b3 single header"), off + strlen("b3"), buf.ptr + strlen(buf.ptr) - (off + strlen("b3")) + 1);
        memcpy(off, "b3 single header", strlen("b3 single header"));
    }
    return true;
}

bool ddtrace_conf_otel_sample_rate(zai_env_buffer buf, bool pre_rinit) {
    if (!get_otel_value((zai_str)ZAI_STRL("OTEL_TRACES_SAMPLER"), buf, pre_rinit)) {
        return false;
    }

    if (strcmp(buf.ptr, "always_on") == 0 || strcmp(buf.ptr, "parentbased_always_on") == 0) {
        memcpy(buf.ptr, ZEND_STRS("1"));
        return true;
    }
    if (strcmp(buf.ptr, "always_off") == 0 || strcmp(buf.ptr, "parentbased_always_off") == 0) {
        memcpy(buf.ptr, ZEND_STRS("0"));
        return true;
    }
    if (strcmp(buf.ptr, "traceidratio") == 0 || strcmp(buf.ptr, "parentbased_traceidratio") == 0) {
        if (get_otel_value((zai_str)ZAI_STRL("OTEL_TRACES_SAMPLER_ARG"), buf, pre_rinit)) {
            return true;
        }
        LOG_ONCE(WARN, "OTEL_TRACES_SAMPLER is %s, but is missing OTEL_TRACES_SAMPLER_ARG", buf.ptr);
    } else {
        LOG_ONCE(WARN, "OTEL_TRACES_SAMPLER has invalid value: %s", buf.ptr);
    }
    report_otel_cfg_telemetry_invalid("OTEL_TRACES_SAMPLER", "trace.sample_rate", pre_rinit);
    return false;
}

bool ddtrace_conf_otel_traces_exporter(zai_env_buffer buf, bool pre_rinit) {
    if (get_otel_value((zai_str)ZAI_STRL("OTEL_TRACES_EXPORTER"), buf, pre_rinit)) {
        if (strcmp(buf.ptr, "none") == 0) {
            memcpy(buf.ptr, ZEND_STRS("0"));
            return true;
        }
        LOG_ONCE(WARN, "OTEL_TRACES_EXPORTER has invalid value: %s", buf.ptr);
        report_otel_cfg_telemetry_invalid("OTEL_TRACES_EXPORTER", "trace.enabled", pre_rinit);
    }
    return false;
}

bool ddtrace_conf_otel_metrics_exporter(zai_env_buffer buf, bool pre_rinit) {
    if (get_otel_value((zai_str)ZAI_STRL("OTEL_METRICS_EXPORTER"), buf, pre_rinit)) {
        if (strcmp(buf.ptr, "none") == 0) {
            memcpy(buf.ptr, ZEND_STRS("0"));
            return true;
        }
        LOG_ONCE(WARN, "OTEL_METRICS_EXPORTER has invalid value: %s", buf.ptr);
        report_otel_cfg_telemetry_invalid("OTEL_METRICS_EXPORTER", "integration_metrics_enabled", pre_rinit);
    }
    return false;
}

bool ddtrace_conf_otel_resource_attributes_tags(zai_env_buffer buf, bool pre_rinit) {
    if (!get_otel_value((zai_str)ZAI_STRL("OTEL_RESOURCE_ATTRIBUTES"), buf, pre_rinit)) {
        return false;
    }

    char *out = buf.ptr;
    int tags = 0;
    for (char *cur = buf.ptr, *key_start = cur; *cur; ++cur) {
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
    if (out != buf.ptr) {
        --out;
    }
    *out = 0;

    return true;
}
