#ifndef DD_INFERRED_PROXY_HEADERS_H
#define DD_INFERRED_PROXY_HEADERS_H

#include "ddtrace.h"
#include "distributed_tracing_headers.h"

typedef struct {
    zend_string *system;
    zend_string *start_time_ms;
    zend_string *path;
    zend_string *http_method;
    zend_string *domain;
    zend_string *stage;
} ddtrace_inferred_proxy_result;

typedef struct {
    const char *span_name;
    const char *component;
} ddtrace_proxy_info;

ddtrace_inferred_proxy_result ddtrace_read_inferred_proxy_headers(ddtrace_read_header *read_header, void *data);
const ddtrace_proxy_info* ddtrace_get_proxy_info(zend_string *system);
void ddtrace_init_proxy_info_map(void);

#endif // DD_INFERRED_PROXY_HEADERS_H