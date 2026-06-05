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

bool ddtrace_conf_otel_otlp_endpoint(zai_env_buffer *buf, bool pre_rinit) {
    ZAI_ENV_BUFFER_INIT(local, ZAI_ENV_MAX_BUFSIZ);
    if (!datadog_get_otel_value((zai_str)ZAI_STRL("OTEL_EXPORTER_OTLP_ENDPOINT"), &local, pre_rinit) || !local.ptr[0]) {
        return false;
    }

    size_t base_len = strlen(local.ptr);
    while (base_len > 0 && local.ptr[base_len - 1] == '/') {
        base_len--;
    }

    const char suffix[] = "/v1/metrics";
    size_t suffix_len = sizeof(suffix) - 1;
    if (base_len + suffix_len + 1 > ZAI_ENV_MAX_BUFSIZ) {
        return false;
    }

    memcpy(buf->ptr, local.ptr, base_len);
    memcpy(buf->ptr + base_len, suffix, suffix_len + 1);
    return true;
}
