#include "profiling.h"

#include "configuration.h"
#include "ddtrace.h"
#include "span.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

DDTRACE_PUBLIC struct ddtrace_profiling_context ddtrace_get_profiling_context(void) {
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
