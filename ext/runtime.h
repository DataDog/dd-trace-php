#ifndef DDTRACE_RUNTIME_H
#define DDTRACE_RUNTIME_H

#include <components/uuid/uuid.h>

extern datadog_php_uuid (*ddtrace_profiling_runtime_id)(void);

#endif  // DDTRACE_RUNTIME_H
