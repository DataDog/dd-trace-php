#include "profiling.h"

#include "configuration.h"
#include "ddtrace.h"
#include "priority_sampling/priority_sampling.h"
#include "span.h"
#include <components-rs/datadog.h>
#include <ext/ffi_utils.h>
#include <ext/string_utils.h>

#ifdef __linux__
#include <sys/syscall.h>
#include <unistd.h>
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
static void ddtrace_otel_u64_be(uint64_t value, uint8_t bytes[8]) {
    for (size_t i = 0; i < 8; ++i) {
        bytes[7 - i] = (uint8_t)(value >> (i * 8));
    }
}

static ddog_CharSlice ddtrace_otel_string(zval *value) {
    return Z_TYPE_P(value) == IS_STRING
        ? dd_zend_string_to_CharSlice(Z_STR_P(value))
        : DDOG_CHARSLICE_C("");
}
#endif

void ddtrace_otel_thread_context_refresh(void) {
#ifdef __linux__
    ddtrace_span_data *span = ddtrace_active_span();
    if (!get_DD_TRACE_ENABLED() || !span || !span->root) {
        datadog_detach_otel_thread_context();
        return;
    }

    ddtrace_root_span_data *root = span->root;
    uint8_t trace_id[16], span_id[8], local_root_span_id[8];
    ddtrace_otel_u64_be(root->trace_id.high, trace_id);
    ddtrace_otel_u64_be(root->trace_id.low, trace_id + 8);
    ddtrace_otel_u64_be(span->span_id, span_id);
    ddtrace_otel_u64_be(root->span_id, local_root_span_id);

    zend_long sampling_priority =
        zval_get_long(&root->property_sampling_priority);
    uint8_t trace_flags =
        sampling_priority != DDTRACE_PRIORITY_SAMPLING_UNKNOWN
            && sampling_priority > 0
        ? 1
        : 0;
    char thread_id[24];
    int thread_id_len = snprintf(
        thread_id,
        sizeof(thread_id),
        "%ld",
        (long)syscall(SYS_gettid));

    datadog_update_otel_thread_context(
        &trace_id,
        &span_id,
        trace_flags,
        &local_root_span_id,
        ddtrace_otel_string(&root->property_service),
        ddtrace_otel_string(&root->property_env),
        ddtrace_otel_string(&root->property_version),
        (ddog_CharSlice){
            .ptr = thread_id,
            .len = thread_id_len > 0 ? (size_t)thread_id_len : 0,
        });
#endif
}

void ddtrace_otel_thread_context_detach(void) {
#ifdef __linux__
    datadog_detach_otel_thread_context();
#endif
}
