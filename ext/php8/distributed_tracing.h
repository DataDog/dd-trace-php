#ifndef DDTRACE_DISTRIBUTED_TRACING_H
#define DDTRACE_DISTRIBUTED_TRACING_H

#include <php.h>

#include "compatibility.h"

void ddtrace_distributed_tracing_rinit(TSRMLS_D);
void ddtrace_distributed_tracing_rshutdown(TSRMLS_D);

#endif  // DDTRACE_DISTRIBUTED_TRACING_H
