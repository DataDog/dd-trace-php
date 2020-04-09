#ifndef DD_SPAN_H
#define DD_SPAN_H
#include <Zend/zend_types.h>
#include <php.h>
#include <stdint.h>
#include <sys/types.h>

#include "clocks.h"
#include "compatibility.h"

struct ddtrace_dispatch_t;

typedef struct ddtrace_span_t {
    zval *span_data;
    ddtrace_exception_t *exception;
    uint64_t trace_id;
    uint64_t parent_id;
    uint64_t span_id;
    ddtrace_realtime_nsec_t start;
    union {
        ddtrace_monotonic_nsec_t duration_start;
        ddtrace_monotonic_nsec_t duration;
    };
    pid_t pid;
    struct ddtrace_span_t *next;

    zend_execute_data *call;
    struct ddtrace_dispatch_t *dispatch;
#if PHP_VERSION_ID < 70000
    zval *retval;
#endif
} ddtrace_span_t;

void ddtrace_init_span_stacks(TSRMLS_D);
void ddtrace_free_span_stacks(TSRMLS_D);

ddtrace_span_t *ddtrace_open_span(zend_execute_data *call, struct ddtrace_dispatch_t *dispatch TSRMLS_DC);
void dd_trace_stop_span_time(ddtrace_span_t *span);
void ddtrace_close_span(TSRMLS_D);
void ddtrace_drop_top_open_span(TSRMLS_D);
void ddtrace_serialize_closed_spans(zval *serialized TSRMLS_DC);

#endif  // DD_SPAN_H
