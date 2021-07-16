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
    zend_object_value obj_value;
    uint64_t trace_id;
    uint64_t parent_id;
    uint64_t span_id;
    uint64_t start;
    uint64_t duration_start;
    uint64_t duration;
};

struct ddtrace_span_fci {
    ddtrace_span_t span;
    zend_execute_data *execute_data;
    struct ddtrace_dispatch_t *dispatch;
#if PHP_VERSION_ID >= 50500
    ddtrace_execute_data dd_execute_data;
#endif
    struct ddtrace_span_fci *next;
};
typedef struct ddtrace_span_fci ddtrace_span_fci;

void ddtrace_init_span_stacks(TSRMLS_D);
void ddtrace_free_span_stacks(TSRMLS_D);

void ddtrace_push_span(ddtrace_span_fci *span_fci TSRMLS_DC);
void ddtrace_open_span(ddtrace_span_fci *span_fci TSRMLS_DC);
ddtrace_span_fci *ddtrace_init_span(TSRMLS_D);
void ddtrace_push_root_span(TSRMLS_D);
void dd_trace_stop_span_time(ddtrace_span_t *span);
bool ddtrace_has_top_internal_span(ddtrace_span_fci *end TSRMLS_DC);
void ddtrace_close_userland_spans_until(ddtrace_span_fci *until TSRMLS_DC);
void ddtrace_close_span(ddtrace_span_fci *span_fci TSRMLS_DC);
void ddtrace_close_all_open_spans(TSRMLS_D);
void ddtrace_drop_top_open_span(TSRMLS_D);
void ddtrace_serialize_closed_spans(zval *serialized TSRMLS_DC);

bool ddtrace_span_alter_root_span_config(zval *old_value, zval *new_value);

#endif  // DD_SPAN_H
