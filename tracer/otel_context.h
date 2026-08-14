#ifndef DDTRACE_OTEL_CONTEXT_H
#define DDTRACE_OTEL_CONTEXT_H

#ifndef __linux__
#error "tracer OTel context publication is Linux-only"
#endif

#include <stdatomic.h>
#include <stdint.h>
#include <zend.h>

BEGIN_EXTERN_C()

typedef struct ddtrace_root_span_data ddtrace_root_span_data;
typedef struct ddtrace_span_stack ddtrace_span_stack;

#define DATADOG_PHP_PROFILING_OTEL_ATTRS_DATA_SIZE 612

/*
 * valid guards updates that require multiple stores to form one consistent snapshot. span_id and
 * trace_flags are atomic because each can also change independently while the record remains valid;
 * a reader may observe either the complete old value or the complete new value.
 */
typedef struct {
    uint64_t trace_id[2];
    _Atomic(uint64_t) span_id;
    _Atomic(uint8_t) valid;
    _Atomic(uint8_t) trace_flags;
    uint16_t attrs_data_size;
    uint8_t attrs_data[DATADOG_PHP_PROFILING_OTEL_ATTRS_DATA_SIZE];
} datadog_otel_thr_ctx_rec;

static inline uint64_t ddtrace_otel_u64_be(uint64_t value) {
#if __BYTE_ORDER__ == __ORDER_LITTLE_ENDIAN__
    return __builtin_bswap64(value);
#elif __BYTE_ORDER__ == __ORDER_BIG_ENDIAN__
    return value;
#else
#error "Unsupported byte order"
#endif
}

/** Atomically update the active span id; a single-field update does not invalidate the record. */
static inline void ddtrace_otel_record_store_span_id(datadog_otel_thr_ctx_rec *record, uint64_t span_id) {
    atomic_store_explicit(&record->span_id, ddtrace_otel_u64_be(span_id), memory_order_relaxed);
}

/** Initialize the record embedded in a newly-created root span. */
void ddtrace_otel_init_root_span(ddtrace_root_span_data *root);

/** Update the trace id and its retained W3C trace flags. */
void ddtrace_otel_update_trace_id(ddtrace_root_span_data *root);

/** Update the effective W3C trace-flags byte. */
void ddtrace_otel_update_trace_flags(ddtrace_root_span_data *root);

/** Refresh service, environment, version, thread id, and local-root metadata. */
void ddtrace_otel_update_attribute_values(ddtrace_root_span_data *root);

/** Attach the record belonging to a span stack, or detach for an empty stack. */
void ddtrace_otel_attach_stack(ddtrace_span_stack *stack);

/** Detach the current thread's record. */
void ddtrace_otel_detach(void);

END_EXTERN_C()

#endif  // DDTRACE_OTEL_CONTEXT_H
