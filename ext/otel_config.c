#include "otel_config.h"
#include <env/env.h>
#include "datadog.h"
#include "endpoints.h"
#include <components-rs/common.h>
#include <components-rs/datadog.h>
#include <ext/ffi_utils.h>
#include <components/log/log.h>
#include <ext/sidecar.h>
#include <ext/telemetry.h>
#include "configuration.h"
#include <ext/standard/url.h>

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

static zend_string *datadog_otel_append_metrics_path(const char *base_endpoint, size_t base_len) {
    while (base_len > 0 && base_endpoint[base_len - 1] == '/') {
        base_len--;
    }

    const char suffix[] = "/v1/metrics";
    size_t suffix_len = sizeof(suffix) - 1;
    zend_string *endpoint = zend_string_alloc(base_len + suffix_len, 0);
    memcpy(ZSTR_VAL(endpoint), base_endpoint, base_len);
    memcpy(ZSTR_VAL(endpoint) + base_len, suffix, suffix_len);
    ZSTR_VAL(endpoint)[base_len + suffix_len] = '\0';
    return endpoint;
}

static zend_string *datadog_otel_build_http_metrics_endpoint(const char *scheme, size_t scheme_len, const char *host, size_t host_len) {
    if (!scheme || scheme_len == 0) {
        scheme = "http";
        scheme_len = sizeof("http") - 1;
    }
    if (!host || host_len == 0) {
        host = "localhost";
        host_len = sizeof("localhost") - 1;
    }

    bool bracket_ipv6 = memchr(host, ':', host_len) && host[0] != '[';
    const char separator[] = "://";
    const char suffix[] = ":4318/v1/metrics";
    size_t separator_len = sizeof(separator) - 1;
    size_t suffix_len = sizeof(suffix) - 1;
    size_t bracket_len = bracket_ipv6 ? 2 : 0;

    zend_string *endpoint = zend_string_alloc(scheme_len + separator_len + bracket_len + host_len + suffix_len, 0);
    char *cursor = ZSTR_VAL(endpoint);
    memcpy(cursor, scheme, scheme_len);
    cursor += scheme_len;
    memcpy(cursor, separator, separator_len);
    cursor += separator_len;
    if (bracket_ipv6) {
        *cursor++ = '[';
    }
    memcpy(cursor, host, host_len);
    cursor += host_len;
    if (bracket_ipv6) {
        *cursor++ = ']';
    }
    memcpy(cursor, suffix, suffix_len);
    cursor += suffix_len;
    *cursor = '\0';
    return endpoint;
}

static ddog_Endpoint *datadog_otel_unix_metrics_endpoint(const char *endpoint, size_t endpoint_len, bool append_metrics_path) {
    const char unix_scheme[] = "unix://";
    size_t unix_scheme_len = sizeof(unix_scheme) - 1;
    const char metrics_path[] = "/v1/metrics";
    size_t metrics_path_len = sizeof(metrics_path) - 1;

    if (endpoint_len <= unix_scheme_len || memcmp(endpoint, unix_scheme, unix_scheme_len) != 0) {
        return NULL;
    }

    const char *socket_path = endpoint + unix_scheme_len;
    size_t socket_path_len = endpoint_len - unix_scheme_len;

    if (!append_metrics_path && socket_path_len > metrics_path_len && memcmp(socket_path + socket_path_len - metrics_path_len, metrics_path, metrics_path_len) == 0) {
        socket_path_len -= metrics_path_len;
    }

    if (socket_path_len > 0 && socket_path[0] == '/') {
        return datadog_otel_metrics_endpoint_from_unix_socket((ddog_CharSlice){
            .ptr = socket_path,
            .len = socket_path_len,
        });
    }

    return NULL;
}

static ddog_Endpoint *datadog_otel_endpoint_from_url(const char *endpoint, size_t endpoint_len, bool append_metrics_path) {
    ddog_Endpoint *unix_endpoint = datadog_otel_unix_metrics_endpoint(endpoint, endpoint_len, append_metrics_path);
    if (unix_endpoint) {
        return unix_endpoint;
    }

    zend_string *endpoint_url = append_metrics_path
        ? datadog_otel_append_metrics_path(endpoint, endpoint_len)
        : zend_string_init(endpoint, endpoint_len, 0);
    ddog_Endpoint *parsed_endpoint = ddog_endpoint_from_url(dd_zend_string_to_CharSlice(endpoint_url));
    zend_string_release(endpoint_url);
    return parsed_endpoint;
}

static ddog_Endpoint *datadog_otel_metrics_endpoint_from_agent_url(const char *agent_url, size_t agent_url_len) {
    zend_string *agent_scheme = NULL;

    ddog_Endpoint *unix_endpoint = datadog_otel_unix_metrics_endpoint(agent_url, agent_url_len, true);
    if (unix_endpoint) {
        return unix_endpoint;
    }

    php_url *parsed = php_url_parse(agent_url);
    if (parsed) {
#if PHP_VERSION_ID >= 70300
        if (parsed->scheme) {
            agent_scheme = zend_string_copy(parsed->scheme);
        }

        if (parsed->host) {
            zend_string *endpoint = datadog_otel_build_http_metrics_endpoint(
                agent_scheme ? ZSTR_VAL(agent_scheme) : NULL,
                agent_scheme ? ZSTR_LEN(agent_scheme) : 0,
                ZSTR_VAL(parsed->host),
                ZSTR_LEN(parsed->host)
            );
            if (agent_scheme) {
                zend_string_release(agent_scheme);
            }
            php_url_free(parsed);
            ddog_Endpoint *parsed_endpoint = ddog_endpoint_from_url(dd_zend_string_to_CharSlice(endpoint));
            zend_string_release(endpoint);
            return parsed_endpoint;
        }
#else
        if (parsed->scheme) {
            agent_scheme = zend_string_init(parsed->scheme, strlen(parsed->scheme), 0);
        }

        if (parsed->host) {
            zend_string *endpoint = datadog_otel_build_http_metrics_endpoint(
                agent_scheme ? ZSTR_VAL(agent_scheme) : NULL,
                agent_scheme ? ZSTR_LEN(agent_scheme) : 0,
                parsed->host,
                strlen(parsed->host)
            );
            if (agent_scheme) {
                zend_string_release(agent_scheme);
            }
            php_url_free(parsed);
            ddog_Endpoint *parsed_endpoint = ddog_endpoint_from_url(dd_zend_string_to_CharSlice(endpoint));
            zend_string_release(endpoint);
            return parsed_endpoint;
        }
#endif
        if (agent_scheme) {
            zend_string_release(agent_scheme);
        }
        php_url_free(parsed);
    }

    zend_string *endpoint = datadog_otel_build_http_metrics_endpoint(NULL, 0, NULL, 0);
    ddog_Endpoint *parsed_endpoint = ddog_endpoint_from_url(dd_zend_string_to_CharSlice(endpoint));
    zend_string_release(endpoint);
    return parsed_endpoint;
}

ddog_Endpoint *datadog_otel_metrics_endpoint(bool pre_rinit) {
    ZAI_ENV_BUFFER_INIT(endpoint, ZAI_ENV_MAX_BUFSIZ);
    if (datadog_get_otel_value((zai_str)ZAI_STRL("OTEL_EXPORTER_OTLP_METRICS_ENDPOINT"), &endpoint, pre_rinit) && endpoint.ptr[0]) {
        return datadog_otel_endpoint_from_url(endpoint.ptr, strlen(endpoint.ptr), false);
    }

    if (datadog_get_otel_value((zai_str)ZAI_STRL("OTEL_EXPORTER_OTLP_ENDPOINT"), &endpoint, pre_rinit) && endpoint.ptr[0]) {
        return datadog_otel_endpoint_from_url(endpoint.ptr, strlen(endpoint.ptr), true);
    }

    if (datadog_get_otel_value((zai_str)ZAI_STRL("DD_TRACE_AGENT_URL"), &endpoint, pre_rinit) && endpoint.ptr[0]) {
        return datadog_otel_metrics_endpoint_from_agent_url(endpoint.ptr, strlen(endpoint.ptr));
    }

    char *agent_url = datadog_agent_url();
    ddog_Endpoint *metrics_endpoint = datadog_otel_metrics_endpoint_from_agent_url(agent_url, strlen(agent_url));
    free(agent_url);
    return metrics_endpoint;
}
