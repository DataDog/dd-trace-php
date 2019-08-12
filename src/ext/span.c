#include "span.h"

#include <php.h>
#include <time.h>  // TODO Add config check

#include "ddtrace.h"
#include "serializer.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void ddtrace_init_span_stacks(TSRMLS_D) {
    DDTRACE_G(open_spans_top) = NULL;
    DDTRACE_G(closed_spans_top) = NULL;
}

static void _free_span(ddtrace_span_stack_t *span) {
#if PHP_VERSION_ID >= 70000
    zval_ptr_dtor(span->span_data);
#else
    Z_DELREF_P(span->span_data);
#endif
    efree(span->span_data);
    if (span->exception) {
#if PHP_VERSION_ID >= 70000
        zval_ptr_dtor(span->exception);
#else
        zval_ptr_dtor(&span->exception);
#endif
        efree(span->exception);
    }
    efree(span);
}

static void _free_span_stack(ddtrace_span_stack_t *span) {
    while (span != NULL) {
        ddtrace_span_stack_t *tmp = span;
        span = tmp->next;
        _free_span(tmp);
    }
}

void ddtrace_free_span_stacks(TSRMLS_D) {
    _free_span_stack(DDTRACE_G(open_spans_top));
    DDTRACE_G(open_spans_top) = NULL;
    _free_span_stack(DDTRACE_G(closed_spans_top));
    DDTRACE_G(closed_spans_top) = NULL;
}

static uint64_t _get_nanoseconds() {
    struct timespec time;
    if (clock_gettime(CLOCK_MONOTONIC, &time) == 0) {
        return time.tv_sec * 1000000000L + time.tv_nsec;
    }
    return 0;
}

ddtrace_span_stack_t *ddtrace_open_span(TSRMLS_D) {
    ddtrace_span_stack_t *stack = ecalloc(1, sizeof(ddtrace_span_stack_t));
    stack->next = DDTRACE_G(open_spans_top);
    DDTRACE_G(open_spans_top) = stack;

    stack->span_data = (zval *) ecalloc(1, sizeof(zval));
    object_init_ex(stack->span_data, ddtrace_ce_span_data);

    // Peek at the active span ID before we push a new one onto the stack
    stack->parent_id = ddtrace_peek_span_id(TSRMLS_C);
    stack->span_id = ddtrace_push_span_id(TSRMLS_C);
    // Set the trace_id last so we have ID's on the stack
    stack->trace_id = DDTRACE_G(root_span_id);
    stack->duration = 0;
    stack->exception = NULL;
    stack->start = _get_nanoseconds();
    return stack;
}

void ddtrace_close_span(TSRMLS_D) {
    ddtrace_span_stack_t *stack = DDTRACE_G(open_spans_top);
    if (stack == NULL) {
        return;
    }
    DDTRACE_G(open_spans_top) = stack->next;

    stack->duration = _get_nanoseconds() - stack->start;
    // Sync with span ID stack
    ddtrace_pop_span_id(TSRMLS_C);
    // TODO Serialize the span onto a buffer and free
    stack->next = DDTRACE_G(closed_spans_top);
    DDTRACE_G(closed_spans_top) = stack;
}

void ddtrace_serialize_closed_spans(zval *serialized TSRMLS_DC) {
    ddtrace_span_stack_t *span = DDTRACE_G(closed_spans_top);
    array_init(serialized);
    while (span != NULL) {
        ddtrace_span_stack_t *tmp = span;
        span = tmp->next;
        ddtrace_serialize_span_to_array(tmp, serialized TSRMLS_CC);
        _free_span(tmp);
    }
    DDTRACE_G(closed_spans_top) = NULL;
}
