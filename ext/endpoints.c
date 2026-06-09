#ifndef _GNU_SOURCE
#define _GNU_SOURCE
#endif

#include "endpoints.h"

#include <components-rs/common.h>
#include <components-rs/datadog.h>

#include "configuration.h"
#include "datadog.h"
#include "ffi_utils.h"

ZEND_EXTERN_MODULE_GLOBALS(datadog);

#define DEFAULT_DOGSTATSD_UDS_PATH "/var/run/datadog/dsd.socket"
#define DEFAULT_AGENT_UDS_PATH "/var/run/datadog/apm.socket"

#define HOST_V6_FORMAT_STR "http://[%s]:%u"
#define HOST_V4_FORMAT_STR "http://%s:%u"

char *datadog_agent_url(void) {
    zend_string *url = get_global_DD_TRACE_AGENT_URL();
    if (ZSTR_LEN(url) > 0) {
        char *dup = zend_strndup(ZSTR_VAL(url), ZSTR_LEN(url) + 1);

        // mess around with backslashes to support our test cases providing something like "file://C:\dir\test.out"
        const char *fileprefix = "file://";
        if (strncmp(ZSTR_VAL(url), fileprefix, strlen(fileprefix)) == 0 && strchr(ZSTR_VAL(url), '\\')) {
            for (size_t i = strlen(fileprefix); i < ZSTR_LEN(url); ++i) {
                if (dup[i] == '\\') {
                    dup[i] = '/';
                }
            }
        }

        return dup;
    }

    zend_string *hostname = get_global_DD_AGENT_HOST();
    if (ZSTR_LEN(hostname) > 7 && strncmp(ZSTR_VAL(hostname), "unix://", 7) == 0) {
        return zend_strndup(ZSTR_VAL(hostname), ZSTR_LEN(hostname));
    }

    if (ZSTR_LEN(hostname) > 0 && zai_config_memoized_entries[DATADOG_CONFIG_DD_AGENT_HOST].name_index != ZAI_CONFIG_ORIGIN_DEFAULT) {
        bool isIPv6 = memchr(ZSTR_VAL(hostname), ':', ZSTR_LEN(hostname));

        int64_t port = get_global_DD_TRACE_AGENT_PORT();
        if (port <= 0 || port > 65535) {
            port = 8126;
        }
        char *formatted_url;
        asprintf(&formatted_url, isIPv6 ? HOST_V6_FORMAT_STR : HOST_V4_FORMAT_STR, ZSTR_VAL(hostname), (uint32_t)port);
        return formatted_url;
    }

    if (access(DEFAULT_AGENT_UDS_PATH, F_OK) == SUCCESS) {
        return zend_strndup(ZEND_STRL("unix://" DEFAULT_AGENT_UDS_PATH));
    }

    int64_t port = get_global_DD_TRACE_AGENT_PORT();
    if (port <= 0 || port > 65535) {
        port = 8126;
    }
    char *formatted_url;
    asprintf(&formatted_url, HOST_V4_FORMAT_STR, "localhost", (uint32_t)port);
    return formatted_url;
}

char *datadog_dogstatsd_url(void) {
    zend_string *url = get_DD_DOGSTATSD_URL();
    if (ZSTR_LEN(url) > 0 && zai_config_memoized_entries[DATADOG_CONFIG_DD_DOGSTATSD_URL].name_index != ZAI_CONFIG_ORIGIN_DEFAULT) {
        return zend_strndup(ZSTR_VAL(url), ZSTR_LEN(url) + 1);
    }

    zend_string *hostname = get_DD_DOGSTATSD_HOST();
    if (ZSTR_LEN(hostname) == 0 || zai_config_memoized_entries[DATADOG_CONFIG_DD_DOGSTATSD_HOST].name_index == ZAI_CONFIG_ORIGIN_DEFAULT) {
        if (zai_config_memoized_entries[DATADOG_CONFIG_DD_AGENT_HOST].name_index == ZAI_CONFIG_ORIGIN_DEFAULT) {
            hostname = ZSTR_EMPTY_ALLOC();
        } else {
            hostname = get_global_DD_AGENT_HOST();
        }
    }

    if (ZSTR_LEN(hostname) > 7 && strncmp(ZSTR_VAL(hostname), "unix://", 7) == 0) {
        return zend_strndup(ZSTR_VAL(hostname), ZSTR_LEN(hostname));
    }

    if (ZSTR_LEN(hostname) > 0) {
        bool isIPv6 = memchr(ZSTR_VAL(hostname), ':', ZSTR_LEN(hostname));

        int port = get_DD_DOGSTATSD_PORT();
        if (port <= 0 || port > 65535) {
            port = 8125;
        }
        char *formatted_url;
        asprintf(&formatted_url, isIPv6 ? HOST_V6_FORMAT_STR : HOST_V4_FORMAT_STR, ZSTR_VAL(hostname), (uint32_t)port);
        return formatted_url;
    }

    if (access(DEFAULT_DOGSTATSD_UDS_PATH, F_OK) == SUCCESS) {
        return zend_strndup(ZEND_STRL("unix://" DEFAULT_DOGSTATSD_UDS_PATH));
    }

    int64_t port = get_global_DD_TRACE_AGENT_PORT();
    if (port <= 0 || port > 65535 || zai_config_memoized_entries[DATADOG_CONFIG_DD_TRACE_AGENT_PORT].name_index == ZAI_CONFIG_ORIGIN_DEFAULT) {
        port = 8125;
    }
    char *formatted_url;
    asprintf(&formatted_url, HOST_V4_FORMAT_STR, "localhost", (uint32_t)port);
    return formatted_url;
}

ddog_Endpoint *datadog_otel_metrics_endpoint(void) {
    zend_string *endpoint_url = get_global_OTEL_EXPORTER_OTLP_METRICS_ENDPOINT();
    if (ZSTR_LEN(endpoint_url) > 0) {
        return datadog_otel_metrics_endpoint_from_url(dd_zend_string_to_CharSlice(endpoint_url));
    }

    char *agent_url = datadog_agent_url();
    ddog_Endpoint *metrics_endpoint = datadog_otel_metrics_endpoint_from_agent_url((ddog_CharSlice){.ptr = agent_url, .len = strlen(agent_url)});
    free(agent_url);
    return metrics_endpoint;
}

// Builds the OTLP traces endpoint, mirroring datadog_otel_metrics_endpoint():
// the explicit OTEL_EXPORTER_OTLP_TRACES_ENDPOINT (or its
// OTEL_EXPORTER_OTLP_ENDPOINT -> /v1/traces fallback resolved by the config
// layer) is used as-is; otherwise the computed default
// http://<agent_host>:4318/v1/traces is derived from the agent URL.
// The Rust builders (datadog_otel_traces_endpoint_from_url /
// _from_agent_url) own the URL handling, exactly like the metrics path.
ddog_Endpoint *datadog_otel_traces_endpoint(void) {
    zend_string *endpoint_url = get_global_OTEL_EXPORTER_OTLP_TRACES_ENDPOINT();
    if (ZSTR_LEN(endpoint_url) > 0) {
        return datadog_otel_traces_endpoint_from_url(dd_zend_string_to_CharSlice(endpoint_url));
    }

    char *agent_url = datadog_agent_url();
    ddog_Endpoint *traces_endpoint = datadog_otel_traces_endpoint_from_agent_url((ddog_CharSlice){.ptr = agent_url, .len = strlen(agent_url)});
    free(agent_url);
    return traces_endpoint;
}

// Resolves the OTLP traces endpoint URL as a heap-allocated, null-terminated
// string (caller must free()). Returns the explicit
// OTEL_EXPORTER_OTLP_TRACES_ENDPOINT verbatim, or the computed default
// http://<agent_host>:4318/v1/traces derived from the agent URL. This is the
// string form passed to the sidecar (see ddog_sidecar_session_set_otlp_traces_endpoint).
char *datadog_otel_traces_url(void) {
    zend_string *endpoint_url = get_global_OTEL_EXPORTER_OTLP_TRACES_ENDPOINT();
    if (ZSTR_LEN(endpoint_url) > 0) {
        return zend_strndup(ZSTR_VAL(endpoint_url), ZSTR_LEN(endpoint_url));
    }

    char *agent_url = datadog_agent_url();
    bool isIPv6 = false;
    const char *host = "localhost";
    char *host_buf = NULL;

    // Extract host from the agent URL (scheme://host:port[/...]). For UDS agent
    // URLs (unix://...), fall back to localhost for the OTLP http endpoint.
    const char *scheme_sep = strstr(agent_url, "://");
    if (scheme_sep && strncmp(agent_url, "unix", 4) != 0) {
        const char *host_start = scheme_sep + 3;
        const char *host_end;
        if (*host_start == '[') {
            // IPv6 literal: http://[::1]:8126
            isIPv6 = true;
            host_start++;
            host_end = strchr(host_start, ']');
        } else {
            host_end = host_start;
            while (*host_end && *host_end != ':' && *host_end != '/') {
                host_end++;
            }
        }
        if (host_end && host_end > host_start) {
            host_buf = zend_strndup(host_start, host_end - host_start);
            host = host_buf;
        }
    }

    char *url;
    asprintf(&url, isIPv6 ? "http://[%s]:4318/v1/traces" : "http://%s:4318/v1/traces", host);
    if (host_buf) {
        free(host_buf);
    }
    free(agent_url);

    // Normalize to a malloc-allocated buffer freeable with free() (asprintf already is).
    return url;
}
