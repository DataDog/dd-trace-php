#ifndef DDTRACE_PROFILING_H
#define DDTRACE_PROFILING_H

// This file provides definitions for communicating with the profiler.

#include <stdint.h>
#include <zend_portability.h>

#include <ext/datadog_export.h>

struct ddtrace_profiling_context {
    uint64_t local_root_span_id, span_id;
};

BEGIN_EXTERN_C()

END_EXTERN_C()

#endif  // DDTRACE_PROFILING_H
