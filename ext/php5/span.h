#ifndef DD_SPAN_H
#define DD_SPAN_H
#include <Zend/zend_types.h>
#include <php.h>
#include <stdint.h>
#include <sys/types.h>

#include "compatibility.h"
#include "ddtrace.h"

// error.type, error.type, error.stack
static const int ddtrace_num_error_tags = 3;

struct ddtrace_dispatch_t;

struct ddtrace_span_t {
    zval *span_data;
    uint64_t trace_id;
    uint64_t parent_id;
    uint64_t span_id;
    uint64_t start;
    union {
        uint64_t duration_start;
        uint64_t duration;
    };
    pid_t pid;
};
typedef struct ddtrace_span_t ddtrace_span_t;

struct ddtrace_span_fci {
    zend_execute_data *execute_data;
    struct ddtrace_dispatch_t *dispatch;
    ddtrace_exception_t *exception;
    ddtrace_execute_data dd_execute_data;
    struct ddtrace_span_fci *next;
    ddtrace_span_t span;
};
typedef struct ddtrace_span_fci ddtrace_span_fci;

void ddtrace_init_span_stacks(TSRMLS_D);
void ddtrace_free_span_stacks(TSRMLS_D);

void ddtrace_push_span(ddtrace_span_fci *span_fci TSRMLS_DC);
void ddtrace_open_span(ddtrace_span_fci *span_fci TSRMLS_DC);
void dd_trace_stop_span_time(ddtrace_span_t *span);
void ddtrace_close_span(TSRMLS_D);
void ddtrace_drop_top_open_span(TSRMLS_D);
void ddtrace_serialize_closed_spans(zval *serialized TSRMLS_DC);

// Prefer ddtrace_drop_top_open_span
void ddtrace_drop_span(ddtrace_span_fci *span_fci);

#endif  // DD_SPAN_H
