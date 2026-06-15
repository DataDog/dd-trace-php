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
 * Publish the current active tracer context through Linux's OTel thread-context
 * TLS slot. On non-Linux builds this is a no-op.
 */
void ddtrace_update_otel_thread_context(void);

/**
 * Detach and release the current OTel thread context. On non-Linux builds this
 * is a no-op.
 */
void ddtrace_detach_otel_thread_context(void);

END_EXTERN_C()

#endif  // DDTRACE_PROFILING_H
