#ifndef DDTRACE_PROFILING_CONTEXT_H
#define DDTRACE_PROFILING_CONTEXT_H

#include <stdint.h>
#include <zend_portability.h>

#include "ddtrace_export.h"

struct ddtrace_profiling_context {
    uint64_t local_root_span_id, span_id;
};

BEGIN_EXTERN_C()

/**
 * Provide the active trace information for the profiler.
 * If there isn’t an active context, return 0 for both values.
 * This needs to be safe to call even if tracing is disabled, but only needs
 * to support being called from a PHP thread.
 */
DDTRACE_PUBLIC struct ddtrace_profiling_context ddtrace_get_profiling_context(void);

END_EXTERN_C()

#endif  // DDTRACE_PROFILING_CONTEXT_H
