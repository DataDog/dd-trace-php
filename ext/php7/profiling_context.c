#include "profiling_context.h"

#include "ddtrace.h"
#include "span.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

DDTRACE_PUBLIC struct ddtrace_profiling_context ddtrace_get_profiling_context(void) {
    struct ddtrace_profiling_context context = {0, 0};
    if (!DDTRACE_G(disable) && DDTRACE_G(open_spans_top)) {
        context.local_root_span_id = DDTRACE_G(open_spans_top)->span.chunk_root->span.span_id;
        context.span_id = DDTRACE_G(open_spans_top)->span.span_id;
    }
    return context;
}
