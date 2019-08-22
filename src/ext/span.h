#ifndef DD_SPAN_H
#define DD_SPAN_H
#include <Zend/zend_types.h>
#include <php.h>
#include <stdint.h>

#include "compatibility.h"

typedef struct ddtrace_span_t {
    zval *span_data;
#if PHP_VERSION_ID < 70000
    zval *exception;
#else
    zend_object *exception;
#endif
    uint64_t trace_id;
    uint64_t parent_id;
    uint64_t span_id;
    uint64_t start;
    union {
        uint64_t duration_start;
        uint64_t duration;
    };
    struct ddtrace_span_t *next;
} ddtrace_span_t;

void ddtrace_init_span_stacks(TSRMLS_D);
void ddtrace_free_span_stacks(TSRMLS_D);
ddtrace_span_t *ddtrace_open_span(TSRMLS_D);
void dd_trace_stop_span_time(ddtrace_span_t *span);
void ddtrace_close_span(TSRMLS_D);
void ddtrace_serialize_closed_spans(zval *serialized TSRMLS_DC);

#endif  // DD_SPAN_H
