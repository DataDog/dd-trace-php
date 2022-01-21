#ifndef DD_SPAN_H
#define DD_SPAN_H
#include <Zend/zend_types.h>
#include <php.h>
#include <stdbool.h>
#include <stdint.h>
#include <sys/types.h>

#include "compatibility.h"
#include "ddtrace.h"
#include "ddtrace_export.h"

// error.type, error.type, error.stack
static const int ddtrace_num_error_tags = 3;

struct ddtrace_dispatch_t;

struct ddtrace_span_t {
    zend_object std;
    zval properties_table_placeholder[8];
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
    struct ddtrace_span_fci *next;
};
typedef struct ddtrace_span_fci ddtrace_span_fci;

void ddtrace_init_span_stacks(void);
void ddtrace_free_span_stacks(void);

void ddtrace_push_span(ddtrace_span_fci *span_fci);
void ddtrace_open_span(ddtrace_span_fci *span_fci);
ddtrace_span_fci *ddtrace_init_span(void);
void ddtrace_push_root_span(void);

// Note that this function is used externally by the appsec extension.
DDTRACE_PUBLIC bool ddtrace_root_span_add_tag(zend_string *tag, zval *value);

void dd_trace_stop_span_time(ddtrace_span_t *span);
bool ddtrace_has_top_internal_span(ddtrace_span_fci *end);
void ddtrace_close_userland_spans_until(ddtrace_span_fci *until);
void ddtrace_close_span(ddtrace_span_fci *span_fci);
void ddtrace_close_all_open_spans(void);
void ddtrace_drop_top_open_span(void);
void ddtrace_serialize_closed_spans(zval *serialized);
zend_string *ddtrace_span_id_as_string(uint64_t id);

bool ddtrace_span_alter_root_span_config(zval *old_value, zval *new_value);

#endif  // DD_SPAN_H
