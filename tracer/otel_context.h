#ifndef DDTRACE_OTEL_CONTEXT_H
#define DDTRACE_OTEL_CONTEXT_H

#include <stdint.h>
#include <string.h>
#include <zend.h>

#ifdef __linux__
#include <stdatomic.h>
#include <components-rs/otel-thread-ctx.h>
#endif

typedef struct ddtrace_root_span_data ddtrace_root_span_data;

#ifdef __linux__
static inline void ddtrace_write_otel_context_u64_be(uint8_t dest[8], uint64_t value) {
    uint64_t be_value =
#if __BYTE_ORDER__ == __ORDER_LITTLE_ENDIAN__
        __builtin_bswap64(value);
#elif __BYTE_ORDER__ == __ORDER_BIG_ENDIAN__
        value;
#else
#error "Unsupported byte order"
#endif
    memcpy(dest, &be_value, sizeof(be_value));
}

static inline void ddtrace_store_otel_thread_context_span_id(ddog_ThreadContextRecord *ctx, uint64_t span_id) {
    uint8_t span_id_bytes[8];
    ddtrace_write_otel_context_u64_be(span_id_bytes, span_id);

    // OTEP 4947 only guarantees 2-byte record alignment, so span_id is bytes, not a uint64_t.
    // Guard even this small update with valid because readers may interrupt between byte stores.
    volatile uint8_t *valid = (volatile uint8_t *)&ctx->valid;
    *valid = 0;
    atomic_signal_fence(memory_order_seq_cst);
    memcpy(ctx->span_id, span_id_bytes, sizeof(ctx->span_id));
    atomic_signal_fence(memory_order_seq_cst);
    *valid = 1;
}
#endif

BEGIN_EXTERN_C()

/**
 * Compatibility no-op for the UserRequest lifecycle. AppSec-specific
 * entrypoint context is not published through this generic OTel context path.
 */
void ddtrace_set_otel_thread_context_root_span(zend_object *root_span);

/**
 * Compatibility no-op for the UserRequest lifecycle.
 */
void ddtrace_clear_otel_thread_context_root_span(void);

/**
 * Publish the active tracer root's OTel context record and attach it to Linux's
 * OTel thread-context TLS slot.
 *
 * On non-Linux builds this is a no-op.
 *
 * Call this when rebuilding the root record is required: opening a root span,
 * changing trace/local-root ids, or changing service/env/version attributes.
 * When there is no selected tracer span/root, this detaches the OTel thread
 * context instead.
 */
void ddtrace_update_otel_thread_context(void);

/**
 * Attach the active tracer root's OTel context record to Linux's TLS slot, or
 * detach when the selected stack has no active tracer context.
 *
 * On non-Linux builds this is a no-op.
 *
 * Call this when switching span stacks or fibers. It only changes which root
 * record is selected; it does not rebuild the record.
 */
void ddtrace_switch_otel_thread_context(void);

/**
 * Detach the current OTel thread context.
 *
 * On non-Linux builds this is a no-op.
 *
 * Call this at hard context boundaries where nothing from the previous tracer
 * context should remain visible to OTel: request start, span-stack cleanup
 * during request shutdown or tracing disable, or when a context update/switch
 * finds no selected tracer span/root.
 */
void ddtrace_detach_otel_thread_context(void);

/**
 * Detach the current OTel thread context if it points at this root span's
 * embedded record.
 *
 * On non-Linux builds this is a no-op.
 */
void ddtrace_detach_otel_thread_context_for_root(ddtrace_root_span_data *root_span);

END_EXTERN_C()

#endif  // DDTRACE_OTEL_CONTEXT_H
