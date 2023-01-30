#include "profiling.h"

#include "configuration.h"
#include "ddtrace.h"
#include "span.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

DDTRACE_PUBLIC struct ddtrace_profiling_context ddtrace_get_profiling_context(void) {
    struct ddtrace_profiling_context context = {0, 0};
    if (get_DD_TRACE_ENABLED() && DDTRACE_G(active_stack) && DDTRACE_G(active_stack)->root_span) {
        context.local_root_span_id = DDTRACE_G(active_stack)->root_span->span_id;
        context.span_id = ddtrace_active_span()->span_id;
    }
    return context;
}
