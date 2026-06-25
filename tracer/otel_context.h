#ifndef DDTRACE_OTEL_CONTEXT_H
#define DDTRACE_OTEL_CONTEXT_H

#include <stdint.h>
#include <zend.h>

BEGIN_EXTERN_C()

typedef struct ddtrace_root_span_data ddtrace_root_span_data;
typedef struct ddtrace_span_stack ddtrace_span_stack;

#ifdef __linux__
#define DATADOG_PHP_PROFILING_OTEL_ATTRS_DATA_SIZE 612

typedef struct {
    uint64_t trace_id[2];
    _Atomic(uint64_t) span_id;
    _Atomic(uint8_t) valid;
    uint8_t reserved;
    uint16_t attrs_data_size;
    uint8_t attrs_data[DATADOG_PHP_PROFILING_OTEL_ATTRS_DATA_SIZE];
} datadog_otel_thr_ctx_rec;

extern __thread void *otel_thread_ctx_v1;
#endif // __linux__

/**
 * Update only the trace id in an already-initialized OTel thread-context record
 * owned by root. This is used when distributed tracing or RootSpanData::traceId
 * changes the root trace id after the record was published.
 *
 * On non-Linux builds, or when the root record has not been initialized yet,
 * this is a no-op.
 */
void ddtrace_otel_update_trace_id(ddtrace_root_span_data *root);

/**
 * Update only the active span id in an already-initialized OTel thread-context
 * record owned by root. This is used for in-trace active span changes where
 * the root trace id and root-scoped attributes are unchanged.
 *
 * On non-Linux builds, or when the root record has not been initialized yet,
 * this is a no-op.
 */
void ddtrace_otel_update_span_id(ddtrace_root_span_data *root, uint64_t span_id);

/**
 * Rewrite the root-scoped attribute values in an already-initialized OTel
 * thread-context record. Passing NULL selects the current active stack root.
 *
 * On non-Linux builds, when tracing is disabled, or when no suitable initialized
 * root record exists, this is a no-op.
 */
void ddtrace_otel_update_attribute_values(ddtrace_root_span_data *root);

/**
 * Publish the OTel thread-context record for stack's active span. The record is
 * initialized on first publication and otherwise updated with the active span id
 * and latest root-scoped attribute values.
 *
 * If stack has no active root/span, this detaches the current OTel thread
 * context instead. On non-Linux builds this is a no-op.
 */
void ddtrace_otel_attach_stack(ddtrace_span_stack *stack);

/**
 * Clear the current thread's OTel TLS context pointer so no stale tracer context
 * remains visible to external OTel readers.
 *
 * On non-Linux builds this is a no-op.
 */
void ddtrace_otel_detach(void);

/**
 * Detach the current OTel thread context if it points at this root span's
 * embedded record.
 *
 * On non-Linux builds this is a no-op.
 */
void ddtrace_detach_otel_thread_context_for_root(ddtrace_root_span_data *root_span);

END_EXTERN_C()

#endif  // DDTRACE_OTEL_CONTEXT_H
