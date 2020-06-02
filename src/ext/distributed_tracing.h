#ifndef DDTRACE_DISTRIBUTED_TRACING_H
#define DDTRACE_DISTRIBUTED_TRACING_H

#include <php.h>

#define DDTRACE_HTTP_HEADER_PARENT_ID "x-datadog-parent-id"

void ddtrace_distributed_tracing_rinit(TSRMLS_D);
void ddtrace_distributed_tracing_rshutdown(TSRMLS_D);
int ddtrace_distributed_tracing_set_headers(zval *headers TSRMLS_DC);

#endif  // DDTRACE_DISTRIBUTED_TRACING_H
