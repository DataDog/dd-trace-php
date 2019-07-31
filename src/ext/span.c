#include "span.h"

#include <php.h>
#include <time.h> // TODO Add config check

#include "ddtrace.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void dd_trace_init_span_stacks(TSRMLS_D) {
    DDTRACE_G(open_spans_top) = NULL;
    DDTRACE_G(closed_spans_top) = NULL;
}

void _free_span_stack(ddtrace_span_stack_t *stack) {
    while (stack != NULL) {
        ddtrace_span_stack_t *tmp = stack;
        stack = tmp->next;
#if PHP_VERSION_ID >= 70000
        zval_ptr_dtor(tmp->span);
#else
        zval_dtor(tmp->span);
#endif
        if (tmp->exception) {
#if PHP_VERSION_ID >= 70000
            zval_ptr_dtor(tmp->exception);
#else
            zval_dtor(tmp->exception);
#endif
            efree(tmp->exception);
        }
        efree(tmp->span);
        efree(tmp);
    }
}

void dd_trace_free_span_stacks(TSRMLS_D) {
    _free_span_stack(DDTRACE_G(open_spans_top));
    _free_span_stack(DDTRACE_G(closed_spans_top));
}

static uint64_t _get_nanoseconds() {
    struct timespec time;
    if (clock_gettime(CLOCK_MONOTONIC, &time) == 0) {
        return time.tv_sec * 1000000000L + time.tv_nsec;
    }
    return 0;
}

ddtrace_span_stack_t *dd_trace_open_span(TSRMLS_D) {
    ddtrace_span_stack_t *stack = ecalloc(1, sizeof(ddtrace_span_stack_t));
    stack->next = DDTRACE_G(open_spans_top);
    DDTRACE_G(open_spans_top) = stack;

    stack->span = ecalloc(1, sizeof(zval));
    object_init_ex(stack->span, ddtrace_ce_span_data);

    stack->trace_id = DDTRACE_G(root_span_id);
    // We need to peek at the active span ID before we push a new one onto the stack
    stack->parent_id = dd_trace_peek_span_id(TSRMLS_C);
    stack->span_id = dd_trace_push_span_id(TSRMLS_C);
    stack->duration = 0;
    stack->exception = NULL;
    stack->start = _get_nanoseconds();
    return stack;
}

void dd_trace_close_span(TSRMLS_D) {
    ddtrace_span_stack_t *stack = DDTRACE_G(open_spans_top);
    if (stack == NULL) {
        return;
    }
    DDTRACE_G(open_spans_top) = stack->next;

    stack->duration = _get_nanoseconds() - stack->start;
    stack->next = DDTRACE_G(closed_spans_top);
    DDTRACE_G(closed_spans_top) = stack;
}
