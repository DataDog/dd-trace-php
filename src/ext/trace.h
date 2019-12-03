#ifndef DDTRACE_TRACE_H
#define DDTRACE_TRACE_H

#include <php.h>

#include "env_config.h"

BOOL_T ddtrace_tracer_is_limited(TSRMLS_D);

#endif  // DDTRACE_TRACE_H
