#include "ffe.h"

#include "configuration.h"
#include <components-rs/common.h>
#include <components-rs/sidecar.h>
#include <ext/configuration.h>
#include <ext/datadog.h>
#include <ext/ffi_utils.h>
#include <ext/sidecar.h>
#include <ext/standard/url.h>
#include <php.h>
#include <string.h>

ZEND_EXTERN_MODULE_GLOBALS(datadog);

#define DATADOG_FFE_METRIC_BUFFER_LIMIT 1000

typedef struct {
    zend_string *flag_key;
    zend_string *variant;
    zend_string *reason;
    zend_string *error_type;
    zend_string *allocation_key;
} datadog_ffe_metric;

static void datadog_ffe_release_metric(datadog_ffe_metric *metric) {
    if (!metric) {
        return;
    }
    if (metric->flag_key) {
        zend_string_release(metric->flag_key);
    }
    if (metric->variant) {
        zend_string_release(metric->variant);
    }
    if (metric->reason) {
        zend_string_release(metric->reason);
    }
    if (metric->error_type) {
        zend_string_release(metric->error_type);
    }
    if (metric->allocation_key) {
        zend_string_release(metric->allocation_key);
    }
}

static void datadog_ffe_clear_evaluation_metrics(void) {
    datadog_ffe_metric *buffer = (datadog_ffe_metric *) DATADOG_G(ffe_metric_buffer);
    for (size_t i = 0; i < DATADOG_G(ffe_metric_buffer_len); i++) {
        datadog_ffe_release_metric(&buffer[i]);
    }
    if (buffer) {
        efree(buffer);
    }
    DATADOG_G(ffe_metric_buffer) = NULL;
    DATADOG_G(ffe_metric_buffer_len) = 0;
    DATADOG_G(ffe_metric_buffer_cap) = 0;
}

static zend_string *datadog_ffe_append_otlp_metrics_path(const char *base_endpoint, size_t base_len) {
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

static zend_string *datadog_ffe_build_otlp_metrics_endpoint(const char *scheme, size_t scheme_len, const char *host, size_t host_len) {
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

static zend_string *datadog_ffe_otlp_metrics_endpoint_from_agent_config(void) {
    zend_string *agent_scheme = NULL;
    zend_string *agent_url = get_global_DD_TRACE_AGENT_URL();

    if (ZSTR_LEN(agent_url) > 0) {
        php_url *parsed = php_url_parse(ZSTR_VAL(agent_url));
        if (parsed) {
#if PHP_VERSION_ID >= 70300
            if (parsed->scheme) {
                if (zend_string_equals_literal(parsed->scheme, "unix")) {
                    php_url_free(parsed);
                    return zend_string_copy(agent_url);
                }
                agent_scheme = zend_string_copy(parsed->scheme);
            }

            if (parsed->host) {
                zend_string *endpoint = datadog_ffe_build_otlp_metrics_endpoint(
                    agent_scheme ? ZSTR_VAL(agent_scheme) : NULL,
                    agent_scheme ? ZSTR_LEN(agent_scheme) : 0,
                    ZSTR_VAL(parsed->host),
                    ZSTR_LEN(parsed->host)
                );
                if (agent_scheme) {
                    zend_string_release(agent_scheme);
                }
                php_url_free(parsed);
                return endpoint;
            }
#else
            if (parsed->scheme) {
                if (strcmp(parsed->scheme, "unix") == 0) {
                    php_url_free(parsed);
                    return zend_string_copy(agent_url);
                }
                agent_scheme = zend_string_init(parsed->scheme, strlen(parsed->scheme), 0);
            }

            if (parsed->host) {
                zend_string *endpoint = datadog_ffe_build_otlp_metrics_endpoint(
                    agent_scheme ? ZSTR_VAL(agent_scheme) : NULL,
                    agent_scheme ? ZSTR_LEN(agent_scheme) : 0,
                    parsed->host,
                    strlen(parsed->host)
                );
                if (agent_scheme) {
                    zend_string_release(agent_scheme);
                }
                php_url_free(parsed);
                return endpoint;
            }
#endif
            php_url_free(parsed);
        }
    }

    zend_string *agent_host = get_global_DD_AGENT_HOST();
    zend_string *endpoint = datadog_ffe_build_otlp_metrics_endpoint(
        agent_scheme ? ZSTR_VAL(agent_scheme) : NULL,
        agent_scheme ? ZSTR_LEN(agent_scheme) : 0,
        ZSTR_VAL(agent_host),
        ZSTR_LEN(agent_host)
    );
    if (agent_scheme) {
        zend_string_release(agent_scheme);
    }
    return endpoint;
}

static zend_string *datadog_ffe_otlp_metrics_endpoint(void) {
    const char *metrics_endpoint = getenv("OTEL_EXPORTER_OTLP_METRICS_ENDPOINT");
    if (metrics_endpoint && metrics_endpoint[0] != '\0') {
        return zend_string_init(metrics_endpoint, strlen(metrics_endpoint), 0);
    }

    const char *base_endpoint = getenv("OTEL_EXPORTER_OTLP_ENDPOINT");
    if (base_endpoint && base_endpoint[0] != '\0') {
        return datadog_ffe_append_otlp_metrics_path(base_endpoint, strlen(base_endpoint));
    }

    return datadog_ffe_otlp_metrics_endpoint_from_agent_config();
}

bool datadog_ffe_record_evaluation_metric(
    const char *flag_key,
    size_t flag_key_len,
    const char *variant,
    size_t variant_len,
    const char *reason,
    size_t reason_len,
    const char *error_type,
    size_t error_type_len,
    const char *allocation_key,
    size_t allocation_key_len
) {
    if (!get_DD_METRICS_OTEL_ENABLED() || !flag_key || flag_key_len == 0) {
        return false;
    }

    if (DATADOG_G(ffe_metric_buffer_len) >= DATADOG_FFE_METRIC_BUFFER_LIMIT) {
        return false;
    }

    if (DATADOG_G(ffe_metric_buffer_len) == DATADOG_G(ffe_metric_buffer_cap)) {
        size_t new_cap = DATADOG_G(ffe_metric_buffer_cap) == 0 ? 8 : DATADOG_G(ffe_metric_buffer_cap) * 2;
        if (new_cap > DATADOG_FFE_METRIC_BUFFER_LIMIT) {
            new_cap = DATADOG_FFE_METRIC_BUFFER_LIMIT;
        }
        DATADOG_G(ffe_metric_buffer) = safe_erealloc(
            DATADOG_G(ffe_metric_buffer),
            new_cap,
            sizeof(datadog_ffe_metric),
            0
        );
        DATADOG_G(ffe_metric_buffer_cap) = new_cap;
    }

    datadog_ffe_metric *buffer = (datadog_ffe_metric *) DATADOG_G(ffe_metric_buffer);
    datadog_ffe_metric *metric = &buffer[DATADOG_G(ffe_metric_buffer_len)++];
    metric->flag_key = zend_string_init(flag_key, flag_key_len, 0);
    metric->variant = zend_string_init(variant ? variant : "", variant ? variant_len : 0, 0);
    metric->reason = zend_string_init(reason ? reason : "", reason ? reason_len : 0, 0);
    metric->error_type = zend_string_init(error_type ? error_type : "", error_type ? error_type_len : 0, 0);
    metric->allocation_key = zend_string_init(allocation_key ? allocation_key : "", allocation_key ? allocation_key_len : 0, 0);

    return true;
}

bool datadog_ffe_flush_evaluation_metrics(void) {
    size_t metric_count = DATADOG_G(ffe_metric_buffer_len);
    datadog_ffe_metric *buffer = (datadog_ffe_metric *) DATADOG_G(ffe_metric_buffer);

    if (metric_count == 0 || !buffer) {
        return false;
    }

    if (!DATADOG_G(sidecar) || !datadog_sidecar_instance_id || !DATADOG_G(sidecar_queue_id)) {
        datadog_ffe_clear_evaluation_metrics();
        return false;
    }

    zend_string *endpoint = datadog_ffe_otlp_metrics_endpoint();
    ddog_FfeEvaluationMetric *ffi_metrics = safe_emalloc(metric_count, sizeof(ddog_FfeEvaluationMetric), 0);
    for (size_t i = 0; i < metric_count; i++) {
        ffi_metrics[i] = (ddog_FfeEvaluationMetric) {
            .flag_key = dd_zend_string_to_CharSlice(buffer[i].flag_key),
            .variant = dd_zend_string_to_CharSlice(buffer[i].variant),
            .reason = dd_zend_string_to_CharSlice(buffer[i].reason),
            .error_type = dd_zend_string_to_CharSlice(buffer[i].error_type),
            .allocation_key = dd_zend_string_to_CharSlice(buffer[i].allocation_key),
        };
    }

    ddog_FfeTelemetryContext context = {
        .service = dd_zend_string_to_CharSlice(get_DD_SERVICE()),
        .env = dd_zend_string_to_CharSlice(get_DD_ENV()),
        .version = dd_zend_string_to_CharSlice(get_DD_VERSION()),
    };
    ddog_Slice_FfeEvaluationMetric metric_slice = {
        .ptr = ffi_metrics,
        .len = metric_count,
    };

    bool flushed = datadog_ffi_try(
        "Failed sending FFE metrics batch to sidecar",
        ddog_sidecar_send_ffe_evaluation_metrics(
            &DATADOG_G(sidecar),
            datadog_sidecar_instance_id,
            &DATADOG_G(sidecar_queue_id),
            dd_zend_string_to_CharSlice(endpoint),
            &context,
            metric_slice));

    efree(ffi_metrics);
    zend_string_release(endpoint);
    datadog_ffe_clear_evaluation_metrics();
    return flushed;
}
