#include "trace.h"

#include <php.h>

#include "configuration.h"
#include "ddtrace.h"
#include "memory_limit.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

BOOL_T ddtrace_tracer_is_limited(TSRMLS_D) {
    int64_t limit = get_dd_trace_spans_limit();
    if (limit >= 0 && (int64_t)(DDTRACE_G(open_spans_count) + DDTRACE_G(closed_spans_count)) >= limit) {
        return TRUE;
    }
    return ddtrace_check_memory_under_limit(TSRMLS_C) == TRUE ? FALSE : TRUE;
}
