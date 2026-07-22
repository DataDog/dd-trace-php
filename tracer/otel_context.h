#ifndef DDTRACE_OTEL_CONTEXT_H
#define DDTRACE_OTEL_CONTEXT_H

#include <stdint.h>
#include <zend.h>
#ifdef _WIN32
#include <components/atomic_win32_polyfill.h>
#else
#include <stdatomic.h>
#endif

BEGIN_EXTERN_C()

typedef struct ddtrace_root_span_data ddtrace_root_span_data;
typedef struct ddtrace_span_stack ddtrace_span_stack;

#include <ext/datadog_export.h>

#define DATADOG_PHP_PROFILING_OTEL_ATTRS_DATA_SIZE 612

typedef struct {
    _Atomic(uint8_t) trace_id[16];
    _Atomic(uint8_t) span_id[8];
    _Atomic(uint8_t) valid;
    uint8_t reserved;
    _Atomic(uint16_t) attrs_data_size;
    _Atomic(uint8_t) attrs_data[DATADOG_PHP_PROFILING_OTEL_ATTRS_DATA_SIZE];
} datadog_otel_thr_ctx_rec;

/** Return the address of this thread's active OTel context pointer. */
#if !defined(__linux__)
DATADOG_PUBLIC void **ddog_thread_ctx_v1(void);
#endif

/**
 * Initialize the OTel thread-context record embedded in root after root span
 * ids and effective service/env/version data have been populated.
 *
 */
void ddtrace_otel_init_root_span(ddtrace_root_span_data *root);

/**
 * Update only the trace id in the OTel thread-context record owned by root.
 * This is used when distributed tracing or RootSpanData::traceId changes the
 * root trace id after the record was initialized.
 *
 */
void ddtrace_otel_update_trace_id(ddtrace_root_span_data *root);

/**
 * Update only the active span id in the OTel thread-context record owned by
 * root. This is used for in-trace active span changes where the root trace id
 * and root-scoped attributes are unchanged.
 *
 */
void ddtrace_otel_update_span_id(ddtrace_root_span_data *root, uint64_t span_id);

/**
 * Refresh the attributes in root's OTel thread-context record.
 *
 * root must be the local root whose record is being rewritten; it provides
 * datadog.local_root_span_id. service/env/version are resolved from the
 * entrypoint root span for the active stack, falling back to runtime
 * config/defaults when no entrypoint root is available.
 *
 * When tracing is disabled this is a no-op.
 */
void ddtrace_otel_update_attribute_values(ddtrace_root_span_data *root);

/**
 * Publish the OTel thread-context record for stack's active span. This selects
 * the stack root's existing record and refreshes only the active span id.
 *
 * If stack has no active root/span, this detaches the current OTel thread
 * context instead.
 */
void ddtrace_otel_attach_stack(ddtrace_span_stack *stack);

/**
 * Clear the current thread's OTel TLS context pointer so no stale tracer context
 * remains visible to external OTel readers.
 *
 */
void ddtrace_otel_detach(void);

/**
 * Detach the current OTel thread context if it points at this root span's
 * embedded record.
 *
 */
void ddtrace_detach_otel_thread_context_for_root(ddtrace_root_span_data *root_span);

END_EXTERN_C()

#endif  // DDTRACE_OTEL_CONTEXT_H
