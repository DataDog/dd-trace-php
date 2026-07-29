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

/**
 * Provide the active trace information for the profiler.
 * If there isn’t an active context, return 0 for both values.
 * This needs to be safe to call even if tracing is disabled, but only needs
 * to support being called from a PHP thread.
 */
DATADOG_PUBLIC struct ddtrace_profiling_context ddtrace_get_profiling_context(void);

/**
 * Refresh the standard Linux OTel Thread Context from the currently active
 * span stack. This is a no-op on non-Linux platforms.
 */
void ddtrace_otel_thread_context_refresh(void);

/**
 * Detach the standard Linux OTel Thread Context from the calling thread.
 */
void ddtrace_otel_thread_context_detach(void);

END_EXTERN_C()

#endif  // DDTRACE_PROFILING_H
