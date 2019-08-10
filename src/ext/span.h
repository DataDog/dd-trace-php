#ifndef DD_SPAN_H
#define DD_SPAN_H
#include <Zend/zend_types.h>
#include <php.h>
#include <stdint.h>

typedef struct _ddtrace_span_stack_t {
    zval *span_data;
    zval *exception;
    uint64_t trace_id;
    uint64_t parent_id;
    uint64_t span_id;
    uint64_t start;
    uint64_t duration;
    struct _ddtrace_span_stack_t *next;
} ddtrace_span_stack_t;

void dd_trace_init_span_stacks(TSRMLS_D);
void dd_trace_free_span_stacks(TSRMLS_D);
ddtrace_span_stack_t *dd_trace_open_span(TSRMLS_D);
void dd_trace_close_span(TSRMLS_D);

#endif  // DD_SPAN_H
