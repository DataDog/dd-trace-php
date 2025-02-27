#include "inferred_proxy_headers.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static HashTable proxy_info_map;

void ddtrace_add_proxy_info(const char *system, const char *span_name, const char *component) {
    ddtrace_proxy_info *info = pemalloc(sizeof(ddtrace_proxy_info), 1);
    info->span_name = span_name;
    info->component = component;
    zend_hash_str_add_ptr(&proxy_info_map, system, strlen(system), info);
}

static void dd_proxy_info_dtor(zval *zv) {
    pefree(Z_PTR_P(zv), 1);
}

void ddtrace_init_proxy_info_map(void) {
    zend_hash_init(&proxy_info_map, 8, NULL, (dtor_func_t)dd_proxy_info_dtor, 1);

    ddtrace_add_proxy_info("aws-apigateway", "aws.apigateway", "aws-apigateway");

    // Add more proxies using ddtrace_add_proxy_info
}

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

const ddtrace_proxy_info* ddtrace_get_proxy_info(zend_string *system) {
    return zend_hash_find_ptr(&proxy_info_map, system);
}
