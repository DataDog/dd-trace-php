#include "distributed_tracing.h"

#include "compatibility.h"

void ddtrace_distributed_tracing_rinit(TSRMLS_D) {}

void ddtrace_distributed_tracing_rshutdown(TSRMLS_D) {}

int ddtrace_distributed_tracing_set_headers(zval *headers TSRMLS_DC) {
    PHP7_UNUSED(headers);
    return 0;
}
