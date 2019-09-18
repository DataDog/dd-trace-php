#include "span.h"

#include <php.h>
#include <time.h>
#include <unistd.h>

#include "ddtrace.h"
#include "dispatch_compat.h"
#include "serializer.h"

#define USE_REALTIME_CLOCK 0
#define USE_MONOTONIC_CLOCK 1

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void ddtrace_init_span_stacks(TSRMLS_D) {
    DDTRACE_G(open_spans_top) = NULL;
    DDTRACE_G(closed_spans_top) = NULL;
    DDTRACE_G(open_spans_count) = 0;
    DDTRACE_G(closed_spans_count) = 0;
}

static void _free_span(ddtrace_span_t *span) {
#if PHP_VERSION_ID < 70000
    zval_ptr_dtor(&span->span_data);
    if (span->exception) {
        zval_ptr_dtor(&span->exception);
    }
#else
    zval_ptr_dtor(span->span_data);
    efree(span->span_data);
    if (span->exception) {
        OBJ_RELEASE(span->exception);
    }
#endif

    efree(span);
}

static void _free_span_stack(ddtrace_span_t *span) {
    while (span != NULL) {
        ddtrace_span_t *tmp = span;
        span = tmp->next;
        _free_span(tmp);
    }
}

void ddtrace_free_span_stacks(TSRMLS_D) {
    _free_span_stack(DDTRACE_G(open_spans_top));
    DDTRACE_G(open_spans_top) = NULL;
    _free_span_stack(DDTRACE_G(closed_spans_top));
    DDTRACE_G(closed_spans_top) = NULL;
    DDTRACE_G(open_spans_count) = 0;
    DDTRACE_G(closed_spans_count) = 0;
}

static uint64_t _get_nanoseconds(BOOL_T monotonic_clock) {
    struct timespec time;
    if (clock_gettime(monotonic_clock ? CLOCK_MONOTONIC : CLOCK_REALTIME, &time) == 0) {
        return time.tv_sec * 1000000000L + time.tv_nsec;
    }
    return 0;
}

ddtrace_span_t *ddtrace_open_span(TSRMLS_D) {
    ddtrace_span_t *span = ecalloc(1, sizeof(ddtrace_span_t));
    span->next = DDTRACE_G(open_spans_top);
    DDTRACE_G(open_spans_top) = span;

    /* On PHP 5 object_init_ex does not set refcount to 1, but on PHP 7 it does */
#if PHP_VERSION_ID < 70000
    MAKE_STD_ZVAL(span->span_data);
#else
    span->span_data = (zval *)ecalloc(1, sizeof(zval));
#endif
    object_init_ex(span->span_data, ddtrace_ce_span_data);

    // Peek at the active span ID before we push a new one onto the stack
    span->parent_id = ddtrace_peek_span_id(TSRMLS_C);
    span->span_id = ddtrace_push_span_id(TSRMLS_C);
    // Set the trace_id last so we have ID's on the stack
    span->trace_id = DDTRACE_G(root_span_id);
    span->duration_start = _get_nanoseconds(USE_MONOTONIC_CLOCK);
    span->exception = NULL;
    span->pid = getpid();
    // Start time is nanoseconds from unix epoch
    // @see https://docs.datadoghq.com/api/?lang=python#send-traces
    span->start = _get_nanoseconds(USE_REALTIME_CLOCK);
    return span;
}

void dd_trace_stop_span_time(ddtrace_span_t *span) {
    span->duration = _get_nanoseconds(USE_MONOTONIC_CLOCK) - span->duration_start;
}

void ddtrace_close_span(TSRMLS_D) {
    ddtrace_span_t *span = DDTRACE_G(open_spans_top);
    if (span == NULL) {
        return;
    }
    DDTRACE_G(open_spans_top) = span->next;
    // Sync with span ID stack
    ddtrace_pop_span_id(TSRMLS_C);
    // TODO Assuming the tracing closure has run at this point, we can serialize the span onto a buffer with
    // ddtrace_coms_buffer_data() and free the span
    span->next = DDTRACE_G(closed_spans_top);
    DDTRACE_G(closed_spans_top) = span;
}

void ddtrace_drop_span(TSRMLS_D) {
    ddtrace_span_t *span = DDTRACE_G(open_spans_top);
    if (span == NULL) {
        return;
    }
    DDTRACE_G(open_spans_top) = span->next;
    // Sync with span ID stack
    ddtrace_pop_span_id(TSRMLS_C);

    _free_span(span);
}

void ddtrace_serialize_closed_spans(zval *serialized TSRMLS_DC) {
    ddtrace_span_t *span = DDTRACE_G(closed_spans_top);
    array_init(serialized);
    while (span != NULL) {
        ddtrace_span_t *tmp = span;
        span = tmp->next;
        ddtrace_serialize_span_to_array(tmp, serialized TSRMLS_CC);
        _free_span(tmp);
    }
    DDTRACE_G(closed_spans_top) = NULL;
    DDTRACE_G(closed_spans_count) = 0;
}
