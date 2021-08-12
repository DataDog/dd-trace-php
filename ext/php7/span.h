#ifndef DD_SPAN_H
#define DD_SPAN_H
#include <Zend/zend_types.h>
#include <php.h>
#include <stdbool.h>
#include <stdint.h>
#include <sys/types.h>

#include "compatibility.h"
#include "ddtrace.h"

// error.type, error.type, error.stack
static const int ddtrace_num_error_tags = 3;

struct ddtrace_dispatch_t;

struct ddtrace_span_t {
    zend_object std;
    zval properties_table_placeholder[5];
    uint64_t trace_id;
    uint64_t parent_id;
    uint64_t span_id;
    uint64_t start;
    uint64_t duration_start;
    uint64_t duration;
    pid_t pid;
};

struct ddtrace_span_fci {
    ddtrace_span_t span;
    zend_execute_data *execute_data;
    struct ddtrace_dispatch_t *dispatch;
    ddtrace_exception_t *exception;
    struct ddtrace_span_fci *next;
};
typedef struct ddtrace_span_fci ddtrace_span_fci;

void ddtrace_init_span_stacks(void);
void ddtrace_free_span_stacks(void);

void ddtrace_push_span(ddtrace_span_fci *span_fci);
void ddtrace_open_span(ddtrace_span_fci *span_fci);
ddtrace_span_fci *ddtrace_init_span();
void dd_trace_stop_span_time(ddtrace_span_t *span);
void ddtrace_close_span(void);
void ddtrace_drop_top_open_span(void);
void ddtrace_serialize_closed_spans(zval *serialized);

// Prefer ddtrace_drop_top_open_span
void ddtrace_drop_span(ddtrace_span_fci *span_fci);

#endif  // DD_SPAN_H
