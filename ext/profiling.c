#include "profiling.h"

#include "configuration.h"
#include "ddtrace.h"
#include "span.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

DDTRACE_PUBLIC struct ddtrace_profiling_context ddtrace_get_profiling_context(void) {
    struct ddtrace_profiling_context context = {0, 0};
    if (DDTRACE_G(active_stack) && DDTRACE_G(active_stack)->root_span && get_DD_TRACE_ENABLED()) {
        context.local_root_span_id = DDTRACE_G(active_stack)->root_span->span_id;
        context.span_id = SPANDATA(DDTRACE_G(active_stack)->active)->span_id;
    }
    return context;
}
