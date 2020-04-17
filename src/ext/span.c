#include "span.h"

#include <php.h>
#include <unistd.h>

#include "auto_flush.h"
#include "configuration.h"
#include "ddtrace.h"
#include "dispatch.h"
#include "logging.h"
#include "random.h"
#include "serializer.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void ddtrace_init_span_stacks(TSRMLS_D) {
    DDTRACE_G(open_spans_top) = NULL;
    DDTRACE_G(closed_spans_top) = NULL;
    DDTRACE_G(open_spans_count) = 0;
    DDTRACE_G(closed_spans_count) = 0;
}

static void _free_span(ddtrace_span_t *span) {
    if (!span) {
        return;
    }
#if PHP_VERSION_ID < 70000
    if (span->span_data) {
        zval_ptr_dtor(&span->span_data);
        span->span_data = NULL;
    }
    if (span->exception) {
        zval_ptr_dtor(&span->exception);
        span->exception = NULL;
    }
#else
    if (span->span_data) {
        zval_ptr_dtor(span->span_data);
        efree(span->span_data);
        span->span_data = NULL;
    }
    if (span->exception) {
        OBJ_RELEASE(span->exception);
        span->exception = NULL;
    }
#endif

    efree(span);
}

static void ddtrace_drop_span(ddtrace_span_t *span) {
    if (span->dispatch) {
        span->dispatch->busy = 0;
        ddtrace_dispatch_release(span->dispatch);
        span->dispatch = NULL;
    }

    _free_span(span);
}

static void _free_span_stack(ddtrace_span_t *span) {
    while (span != NULL) {
        ddtrace_span_t *tmp = span;
        span = tmp->next;
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

ddtrace_span_t *ddtrace_open_span(zend_execute_data *call, struct ddtrace_dispatch_t *dispatch TSRMLS_DC) {
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
    span->span_id = ddtrace_push_span_id(0 TSRMLS_CC);
    // Set the trace_id last so we have ID's on the stack
    span->trace_id = DDTRACE_G(trace_id);
    span->duration_start = ddtrace_monotonic_nsec();
    span->exception = NULL;
    span->pid = getpid();
    // Start time is nanoseconds from unix epoch
    // @see https://docs.datadoghq.com/api/?lang=python#send-traces
    span->start = ddtrace_realtime_nsec();

    span->call = call;
    span->dispatch = dispatch;
    return span;
}

void dd_trace_stop_span_time(ddtrace_span_t *span) { span->duration = ddtrace_monotonic_nsec() - span->duration_start; }

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

    if (span->dispatch) {
        span->dispatch->busy = 0;
        ddtrace_dispatch_release(span->dispatch);
        span->dispatch = NULL;
    }

    // A userland span might still be open so we check the span ID stack instead of the internal span stack
    if (DDTRACE_G(span_ids_top) == NULL && get_dd_trace_auto_flush_enabled()) {
        if (ddtrace_flush_tracer() == FAILURE) {
            ddtrace_log_debug("Unable to auto flush the tracer");
        }
    }
}

void ddtrace_drop_top_open_span(TSRMLS_D) {
    ddtrace_span_t *span = DDTRACE_G(open_spans_top);
    if (span == NULL) {
        return;
    }
    DDTRACE_G(open_spans_top) = span->next;
    // Sync with span ID stack
    ddtrace_pop_span_id(TSRMLS_C);
    ddtrace_drop_span(span);
}

void ddtrace_serialize_closed_spans(zval *serialized TSRMLS_DC) {
    // The tracer supports only one trace per request so free any remaining open spans
    _free_span_stack(DDTRACE_G(open_spans_top));
    DDTRACE_G(open_spans_top) = NULL;
    DDTRACE_G(open_spans_count) = 0;
    ddtrace_free_span_id_stack(TSRMLS_C);

    ddtrace_span_t *span = DDTRACE_G(closed_spans_top);
    array_init(serialized);
    while (span != NULL) {
        ddtrace_span_t *tmp = span;
        span = tmp->next;
        ddtrace_serialize_span_to_array(tmp, serialized TSRMLS_CC);
        _free_span(tmp);
        // Move the stack down one as ddtrace_serialize_span_to_array() might do a long jump
        DDTRACE_G(closed_spans_top) = span;
    }
    DDTRACE_G(closed_spans_top) = NULL;
    DDTRACE_G(closed_spans_count) = 0;
    // Reset the span ID stack and trace ID
    ddtrace_free_span_id_stack(TSRMLS_C);
}
