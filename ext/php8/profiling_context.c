#include "profiling_context.h"

#include "ddtrace.h"
#include "span.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static uint64_t dd_get_span_id(ddtrace_span_fci *span) { return span ? span->span.span_id : 0; }

DDTRACE_PUBLIC struct ddtrace_profiling_context ddtrace_get_profiling_context(void) {
    struct ddtrace_profiling_context context = {0, 0};
    if (!DDTRACE_G(disable)) {
        context.local_root_span_id = dd_get_span_id(DDTRACE_G(root_span));
        context.span_id = dd_get_span_id(DDTRACE_G(open_spans_top));
    }
    return context;
}
