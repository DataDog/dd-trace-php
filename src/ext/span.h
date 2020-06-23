#ifndef DD_SPAN_H
#define DD_SPAN_H
#include <Zend/zend_types.h>
#include <php.h>
#include <stdint.h>
#include <sys/types.h>

#include "compatibility.h"

struct ddtrace_dispatch_t;

typedef struct ddtrace_span_t {
    ddtrace_exception_t *exception;
    uint64_t trace_id;
    uint64_t parent_id;
    uint64_t span_id;
    uint64_t start;
    union {
        uint64_t duration_start;
        uint64_t duration;
    };
    pid_t pid;
    struct ddtrace_span_t *next;

    zend_execute_data *call;
    struct ddtrace_dispatch_t *dispatch;
#if PHP_VERSION_ID < 70000
    zval *retval;
#endif
    zend_object span_data;
} ddtrace_span_t;

// TODO: Remove reference to this in PHP 5 serializer and make static
extern zend_class_entry *ddtrace_ce_span_data;

#if PHP_VERSION_ID >= 70000
zval *ddtrace_spandata_property_name(zend_object *spandata);
zval *ddtrace_spandata_property_resource(zend_object *spandata);
zval *ddtrace_spandata_property_service(zend_object *spandata);
zval *ddtrace_spandata_property_type(zend_object *spandata);
zval *ddtrace_spandata_property_meta(zend_object *spandata);
zval *ddtrace_spandata_property_metrics(zend_object *spandata);
#endif

void ddtrace_span_minit(TSRMLS_D);
void ddtrace_init_span_stacks(TSRMLS_D);
void ddtrace_free_span_stacks(TSRMLS_D);

ddtrace_span_t *ddtrace_open_span(zend_execute_data *call, struct ddtrace_dispatch_t *dispatch TSRMLS_DC);
void dd_trace_stop_span_time(ddtrace_span_t *span);
void ddtrace_close_span(TSRMLS_D);
void ddtrace_drop_top_open_span(TSRMLS_D);
void ddtrace_serialize_closed_spans(zval *serialized TSRMLS_DC);

static inline ddtrace_span_t *ddtrace_spandata_fetch_object(zend_object *obj) {
    return (ddtrace_span_t *)((char*)(obj) - XtOffsetOf(ddtrace_span_t, span_data));
}
#define Z_DDTRACE_SPANDATA_P(zv) ddtrace_spandata_fetch_object(Z_OBJ_P(zv))

#endif  // DD_SPAN_H
