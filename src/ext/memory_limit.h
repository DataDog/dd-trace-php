#ifndef DD_TRACE_MEMORY_LIMIT_H
#define DD_TRACE_MEMORY_LIMIT_H

#include "compatibility.h"
#include "env_config.h"

#define ALLOWED_MAX_MEMORY_USE_IN_PERCENT_OF_MEMORY_LIMIT 0.8

int64_t ddtrace_get_memory_limit(TSRMLS_D);
BOOL_T ddtrace_check_memory_under_limit(TSRMLS_D);

#endif  // DD_TRACE_MEMORY_LIMIT_H
