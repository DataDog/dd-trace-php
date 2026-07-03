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
 * Provide the currently-published OTel process context mapping for the profiler. Writes the base
 * pointer/length into the out-params and returns true, or returns false when nothing is published
 * (or the publisher forked and hasn't republished, in which case the mapping is not safe to read).
 *
 * Lives here (rather than the profiler reading the mapping pointer directly) because the owning
 * handle is process-scope state in the extension's shared object; the profiler is a separate shared
 * object and must pull the live pointer through this exported entry point.
 */
DATADOG_PUBLIC bool ddtrace_get_otel_process_ctx_mapping(const uint8_t **base_out, uintptr_t *len_out);

END_EXTERN_C()

#endif  // DDTRACE_PROFILING_H
