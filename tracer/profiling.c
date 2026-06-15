#include "profiling.h"

#include "configuration.h"
#include "ddtrace.h"
#include "span.h"

#ifdef __linux__
#include <components-rs/otel-thread-ctx.h>
#include <string.h>
#endif

ZEND_EXTERN_MODULE_GLOBALS(datadog);

DATADOG_PUBLIC struct ddtrace_profiling_context ddtrace_get_profiling_context(void) {
    struct ddtrace_profiling_context context = {0, 0};
    // NOTE: `active_stack->active` may legitimately be NULL during span close (e.g. when closing the last span on a
    // stack, `ddtrace_close_top_span_without_stack_swap()` updates it before running additional logic that may still
    // allocate, such as JSON encoding during sampling decisions). Allocation profiling can call into this function
    // from within those allocations, so treat "no active span" as "no profiling context" instead of dereferencing.
    if (DDTRACE_G(active_stack) && DDTRACE_G(active_stack)->root_span && DDTRACE_G(active_stack)->active && get_DD_TRACE_ENABLED()) {
        context.local_root_span_id = DDTRACE_G(active_stack)->root_span->span_id;
        context.span_id = SPANDATA(DDTRACE_G(active_stack)->active)->span_id;
    }
    return context;
}

#ifdef __linux__
static inline void ddtrace_write_u64_be(uint8_t dest[8], uint64_t value) {
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

static void ddtrace_trace_id_to_otel_bytes(datadog_trace_id trace_id, uint8_t dest[16]) {
    ddtrace_write_u64_be(dest, trace_id.high);
    ddtrace_write_u64_be(dest + 8, trace_id.low);
}

void ddtrace_detach_otel_thread_context(void) {
    struct ddog_ThreadContextHandle *ctx = ddog_otel_thread_ctx_detach();
    ddog_otel_thread_ctx_free(ctx);
}

void ddtrace_update_otel_thread_context(void) {
    if (!DDTRACE_G(active_stack) || !DDTRACE_G(active_stack)->root_span || !DDTRACE_G(active_stack)->active ||
        !get_DD_TRACE_ENABLED()) {
        ddtrace_detach_otel_thread_context();
        return;
    }

    ddtrace_root_span_data *root = DDTRACE_G(active_stack)->root_span;
    ddtrace_span_data *span = SPANDATA(DDTRACE_G(active_stack)->active);

    uint8_t trace_id[16];
    uint8_t span_id[8];
    uint8_t local_root_span_id[8];

    ddtrace_trace_id_to_otel_bytes(root->trace_id, trace_id);
    ddtrace_write_u64_be(span_id, span->span_id);
    ddtrace_write_u64_be(local_root_span_id, root->span_id);

    ddog_otel_thread_ctx_update(&trace_id, &span_id, &local_root_span_id);
}
#else
void ddtrace_detach_otel_thread_context(void) {}
void ddtrace_update_otel_thread_context(void) {}
#endif
