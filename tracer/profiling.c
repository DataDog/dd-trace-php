#include "profiling.h"

#include "configuration.h"
#include "ddtrace.h"
#include "span.h"

#ifdef __linux__
#include <components-rs/otel-thread-ctx.h>
#include <stdatomic.h>
#include <stdbool.h>
#include <stddef.h>
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
typedef struct ddtrace_otel_thread_context_record {
    uint8_t trace_id[16];
    uint8_t span_id[8];
    _Atomic uint8_t valid;
    uint8_t reserved;
    uint16_t attrs_data_size;
    uint8_t attrs_data[ddog_MAX_ATTRS_DATA_SIZE];
} ddtrace_otel_thread_context_record;

_Static_assert(sizeof(ddtrace_otel_thread_context_record) == 640, "unexpected OTel thread context record size");
_Static_assert(offsetof(ddtrace_otel_thread_context_record, trace_id) == 0,
    "unexpected OTel thread context trace_id offset");
_Static_assert(offsetof(ddtrace_otel_thread_context_record, span_id) == 16,
    "unexpected OTel thread context span_id offset");
_Static_assert(offsetof(ddtrace_otel_thread_context_record, valid) == 24,
    "unexpected OTel thread context valid offset");
_Static_assert(offsetof(ddtrace_otel_thread_context_record, reserved) == 25,
    "unexpected OTel thread context reserved offset");
_Static_assert(offsetof(ddtrace_otel_thread_context_record, attrs_data_size) == 26,
    "unexpected OTel thread context attrs_data_size offset");
_Static_assert(offsetof(ddtrace_otel_thread_context_record, attrs_data) == 28,
    "unexpected OTel thread context attrs_data offset");

extern void **libdd_get_otel_thread_ctx_v1(void);

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

static inline uint64_t ddtrace_read_u64_be(const uint8_t src[8]) {
    uint64_t be_value;
    memcpy(&be_value, src, sizeof(be_value));

#if __BYTE_ORDER__ == __ORDER_LITTLE_ENDIAN__
    return __builtin_bswap64(be_value);
#elif __BYTE_ORDER__ == __ORDER_BIG_ENDIAN__
    return be_value;
#else
#error "Unsupported byte order"
#endif
}

static void ddtrace_trace_id_to_otel_bytes(datadog_trace_id trace_id, uint8_t dest[16]) {
    ddtrace_write_u64_be(dest, trace_id.high);
    ddtrace_write_u64_be(dest + 8, trace_id.low);
}

static inline uint8_t ddtrace_hex_to_u4(uint8_t hex) {
    if (hex >= '0' && hex <= '9') {
        return (uint8_t)(hex - '0');
    }
    if (hex >= 'a' && hex <= 'f') {
        return (uint8_t)(hex - 'a' + 10);
    }
    if (hex >= 'A' && hex <= 'F') {
        return (uint8_t)(hex - 'A' + 10);
    }
    return UINT8_MAX;
}

static bool ddtrace_parse_u64_hex(const uint8_t hex[16], uint64_t *value) {
    uint64_t result = 0;

    for (size_t i = 0; i < 16; ++i) {
        uint8_t nibble = ddtrace_hex_to_u4(hex[i]);
        if (nibble == UINT8_MAX) {
            return false;
        }
        result = (result << 4) | nibble;
    }

    *value = result;
    return true;
}

static uint64_t ddtrace_otel_context_local_root_span_id(const ddtrace_otel_thread_context_record *record) {
    if (record->attrs_data_size < 18 || record->attrs_data[0] != 0 || record->attrs_data[1] != 16) {
        return 0;
    }

    uint64_t local_root_span_id = 0;
    if (!ddtrace_parse_u64_hex(record->attrs_data + 2, &local_root_span_id)) {
        return 0;
    }

    return local_root_span_id;
}

DATADOG_PUBLIC struct ddtrace_profiling_context ddtrace_get_profiling_otel_context(void) {
    struct ddtrace_profiling_context context = {0, 0};
    ddtrace_otel_thread_context_record *record =
        (ddtrace_otel_thread_context_record *)*libdd_get_otel_thread_ctx_v1();
    if (!record || atomic_load_explicit(&record->valid, memory_order_relaxed) != 1) {
        return context;
    }

    atomic_signal_fence(memory_order_acquire);

    context.span_id = ddtrace_read_u64_be(record->span_id);
    context.local_root_span_id = ddtrace_otel_context_local_root_span_id(record);

    atomic_signal_fence(memory_order_acquire);

    if (atomic_load_explicit(&record->valid, memory_order_relaxed) != 1) {
        return (struct ddtrace_profiling_context){0, 0};
    }

    return context;
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
