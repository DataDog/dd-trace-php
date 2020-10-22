#include "span.h"

#include <php.h>
#include <time.h>
#include <unistd.h>

#include "auto_flush.h"
#include "configuration.h"
#include "ddtrace.h"
#include "dispatch.h"
#include "logging.h"
#include "random.h"
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

static void _free_span(ddtrace_span_fci *span_fci) {
    if (!span_fci) {
        return;
    }
    ddtrace_span_t *span = &span_fci->span;
#if PHP_VERSION_ID < 70000
    if (span->span_data) {
        zval_ptr_dtor(&span->span_data);
        span->span_data = NULL;
    }
    if (span_fci->exception) {
        zval_ptr_dtor(&span_fci->exception);
        span_fci->exception = NULL;
    }
#else
    if (span->span_data) {
        zval_ptr_dtor(span->span_data);
        efree(span->span_data);
        span->span_data = NULL;
    }
    if (span_fci->exception) {
        OBJ_RELEASE(span_fci->exception);
        span_fci->exception = NULL;
    }
#endif

    efree(span_fci);
}

void ddtrace_drop_span(ddtrace_span_fci *span_fci) {
    if (span_fci->dispatch) {
        ddtrace_dispatch_release(span_fci->dispatch);
        span_fci->dispatch = NULL;
    }

    _free_span(span_fci);
}

static void _free_span_stack(ddtrace_span_fci *span_fci) {
    while (span_fci != NULL) {
        ddtrace_span_fci *tmp = span_fci;
        span_fci = tmp->next;
        ddtrace_drop_span(tmp);
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

void ddtrace_push_span(ddtrace_span_fci *span_fci TSRMLS_DC) {
    span_fci->next = DDTRACE_G(open_spans_top);
    DDTRACE_G(open_spans_top) = span_fci;
}

void ddtrace_open_span(ddtrace_span_fci *span_fci TSRMLS_DC) {
    ddtrace_push_span(span_fci TSRMLS_CC);

    ddtrace_span_t *span = &span_fci->span;

    /* On PHP 5 object_init_ex does not set refcount to 1, but on PHP 7 it does */
#if PHP_VERSION_ID < 70000
    MAKE_STD_ZVAL(span->span_data);
#else
    span->span_data = (zval *)ecalloc(1, sizeof(zval));
#endif
    object_init_ex(span->span_data, ddtrace_ce_span_data);

    // Peek at the active span ID before we push a new one onto the stack
    span->parent_id = ddtrace_peek_span_id(TSRMLS_C);
    span->span_id = ddtrace_push_span_id(0 TSRMLS_CC);
    // Set the trace_id last so we have ID's on the stack
    span->trace_id = DDTRACE_G(trace_id);
    span->duration_start = _get_nanoseconds(USE_MONOTONIC_CLOCK);
    span->pid = getpid();
    // Start time is nanoseconds from unix epoch
    // @see https://docs.datadoghq.com/api/?lang=python#send-traces
    span->start = _get_nanoseconds(USE_REALTIME_CLOCK);
}

void dd_trace_stop_span_time(ddtrace_span_t *span) {
    span->duration = _get_nanoseconds(USE_MONOTONIC_CLOCK) - span->duration_start;
}

void ddtrace_close_span(TSRMLS_D) {
    ddtrace_span_fci *span_fci = DDTRACE_G(open_spans_top);
    if (span_fci == NULL) {
        return;
    }
    DDTRACE_G(open_spans_top) = span_fci->next;
    // Sync with span ID stack
    ddtrace_pop_span_id(TSRMLS_C);
    // TODO Assuming the tracing closure has run at this point, we can serialize the span onto a buffer with
    // ddtrace_coms_buffer_data() and free the span
    span_fci->next = DDTRACE_G(closed_spans_top);
    DDTRACE_G(closed_spans_top) = span_fci;

    if (span_fci->dispatch) {
        ddtrace_dispatch_release(span_fci->dispatch);
        span_fci->dispatch = NULL;
    }

    // A userland span might still be open so we check the span ID stack instead of the internal span stack
    if (DDTRACE_G(span_ids_top) == NULL && get_dd_trace_auto_flush_enabled()) {
        if (ddtrace_flush_tracer() == FAILURE) {
            ddtrace_log_debug("Unable to auto flush the tracer");
        }
    }
}

void ddtrace_drop_top_open_span(TSRMLS_D) {
    ddtrace_span_fci *span_fci = DDTRACE_G(open_spans_top);
    if (span_fci == NULL) {
        return;
    }
    DDTRACE_G(open_spans_top) = span_fci->next;
    // Sync with span ID stack
    ddtrace_pop_span_id(TSRMLS_C);
    ddtrace_drop_span(span_fci);
}

void ddtrace_serialize_closed_spans(zval *serialized TSRMLS_DC) {
    // The tracer supports only one trace per request so free any remaining open spans
    _free_span_stack(DDTRACE_G(open_spans_top));
    DDTRACE_G(open_spans_top) = NULL;
    DDTRACE_G(open_spans_count) = 0;
    ddtrace_free_span_id_stack(TSRMLS_C);
    // Clear out additional trace meta; re-initialize it to empty
    zval_dtor(&DDTRACE_G(additional_trace_meta));
    array_init(&DDTRACE_G(additional_trace_meta));

    ddtrace_span_fci *span_fci = DDTRACE_G(closed_spans_top);
    array_init(serialized);
    while (span_fci != NULL) {
        ddtrace_span_fci *tmp = span_fci;
        span_fci = tmp->next;
        ddtrace_serialize_span_to_array(tmp, serialized TSRMLS_CC);
        _free_span(tmp);
        // Move the stack down one as ddtrace_serialize_span_to_array() might do a long jump
        DDTRACE_G(closed_spans_top) = span_fci;
    }
    DDTRACE_G(closed_spans_top) = NULL;
    DDTRACE_G(closed_spans_count) = 0;
    // Reset the span ID stack and trace ID
    ddtrace_free_span_id_stack(TSRMLS_C);
}
