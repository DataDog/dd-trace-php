#include "inferred_proxy_headers.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

ddtrace_inferred_proxy_result ddtrace_read_inferred_proxy_headers(ddtrace_read_header *read_header, void *data) {
    ddtrace_inferred_proxy_result result = {0};

    read_header((zai_str)ZAI_STRL("X_DD_PROXY"), "x-dd-proxy", &result.system, data);
    read_header((zai_str)ZAI_STRL("X_DD_PROXY_REQUEST_TIME_MS"), "x-dd-proxy-request-time-ms", &result.start_time_ms, data);
    read_header((zai_str)ZAI_STRL("X_DD_PROXY_PATH"), "x-dd-proxy-path", &result.path, data);
    read_header((zai_str)ZAI_STRL("X_DD_PROXY_HTTPMETHOD"), "x-dd-proxy-httpmethod", &result.http_method, data);
    read_header((zai_str)ZAI_STRL("X_DD_PROXY_DOMAIN_NAME"), "x-dd-proxy-domain-name", &result.domain, data);
    read_header((zai_str)ZAI_STRL("X_DD_PROXY_STAGE"), "x-dd-proxy-stage", &result.stage, data);

    return result;
}