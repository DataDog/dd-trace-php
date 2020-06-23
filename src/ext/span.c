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

/* class DDTrace\SpanData {} */
zend_class_entry *ddtrace_ce_span_data;
static zend_object_handlers _dd_spandata_object_handlers;

static zend_object *_dd_new_spandata(zend_class_entry *ce) {
    UNUSED(ce);
    ddtrace_span_t *span = ddtrace_open_span(EG(current_execute_data), NULL);
    // Hang onto the object even after it goes out of scope in userland
    GC_ADDREF(&span->span_data);
    return &span->span_data;
}

ZEND_BEGIN_ARG_INFO(arginfo_ddtrace_spandata_close, 0)
ZEND_END_ARG_INFO()

static PHP_METHOD(SpanData, close) {
    zval *span_zv = getThis();
    if (!span_zv) {
        ddtrace_log_debug("Cannot close userland span; error fetching '$this'");
        RETURN_BOOL(0);
    }
    if (Z_DDTRACE_SPANDATA_P(span_zv) != DDTRACE_G(open_spans_top)) {
        ddtrace_log_debugf("Cannot close DDTrace\\SpanData #%d; spans out of sync", Z_OBJ_HANDLE_P(span_zv));
        RETURN_BOOL(0);
    }
    ddtrace_close_span();
    RETURN_BOOL(1);
}

static const zend_function_entry _dd_spandata_functions[] = {
    PHP_ME(SpanData, close, arginfo_ddtrace_spandata_close, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

void ddtrace_span_minit(TSRMLS_D) {
    zend_class_entry ce_span_data;
    INIT_NS_CLASS_ENTRY(ce_span_data, "DDTrace", "SpanData", _dd_spandata_functions);
    ddtrace_ce_span_data = zend_register_internal_class(&ce_span_data TSRMLS_CC);
    ddtrace_ce_span_data->create_object = _dd_new_spandata;

    memcpy(&_dd_spandata_object_handlers, &std_object_handlers, sizeof(zend_object_handlers));
    _dd_spandata_object_handlers.offset = XtOffsetOf(ddtrace_span_t, span_data);

    // trace_id, span_id, parent_id, start & duration are stored directly on
    // ddtrace_span_t so we don't need to make them properties on DDTrace\SpanData
    /*
     * ORDER MATTERS: If you make any changes to the properties below, update the
     * corresponding ddtrace_spandata_property_*() function with the proper offset.
     */
    zend_declare_property_null(ddtrace_ce_span_data, "name", sizeof("name") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_ce_span_data, "resource", sizeof("resource") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_ce_span_data, "service", sizeof("service") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_ce_span_data, "type", sizeof("type") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_ce_span_data, "meta", sizeof("meta") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
    zend_declare_property_null(ddtrace_ce_span_data, "metrics", sizeof("metrics") - 1, ZEND_ACC_PUBLIC TSRMLS_CC);
}

#if PHP_VERSION_ID >= 70000
// SpanData::$name
zval *ddtrace_spandata_property_name(zend_object *spandata) { return OBJ_PROP_NUM(spandata, 0); }
// SpanData::$resource
zval *ddtrace_spandata_property_resource(zend_object *spandata) { return OBJ_PROP_NUM(spandata, 1); }
// SpanData::$service
zval *ddtrace_spandata_property_service(zend_object *spandata) { return OBJ_PROP_NUM(spandata, 2); }
// SpanData::$type
zval *ddtrace_spandata_property_type(zend_object *spandata) { return OBJ_PROP_NUM(spandata, 3); }
// SpanData::$meta
zval *ddtrace_spandata_property_meta(zend_object *spandata) { return OBJ_PROP_NUM(spandata, 4); }
// SpanData::$metrics
zval *ddtrace_spandata_property_metrics(zend_object *spandata) { return OBJ_PROP_NUM(spandata, 5); }
#endif

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
    if (span->exception) {
        OBJ_RELEASE(span->exception);
        span->exception = NULL;
    }
    zend_object_std_dtor(&span->span_data);
    OBJ_RELEASE(&span->span_data);
#endif
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

static uint64_t _get_nanoseconds(BOOL_T monotonic_clock) {
    struct timespec time;
    if (clock_gettime(monotonic_clock ? CLOCK_MONOTONIC : CLOCK_REALTIME, &time) == 0) {
        return time.tv_sec * 1000000000L + time.tv_nsec;
    }
    return 0;
}

ddtrace_span_t *ddtrace_open_span(zend_execute_data *call, struct ddtrace_dispatch_t *dispatch TSRMLS_DC) {
    ddtrace_span_t *span = zend_object_alloc(sizeof(ddtrace_span_t), ddtrace_ce_span_data);
    span->next = DDTRACE_G(open_spans_top);
    DDTRACE_G(open_spans_top) = span;

    zend_object_std_init(&span->span_data, ddtrace_ce_span_data);
    object_properties_init(&span->span_data, ddtrace_ce_span_data);
    span->span_data.handlers = &_dd_spandata_object_handlers;

    // Peek at the active span ID before we push a new one onto the stack
    span->parent_id = ddtrace_peek_span_id(TSRMLS_C);
    span->span_id = ddtrace_push_span_id(0 TSRMLS_CC);
    // Set the trace_id last so we have ID's on the stack
    span->trace_id = DDTRACE_G(trace_id);
    span->duration_start = _get_nanoseconds(USE_MONOTONIC_CLOCK);
    span->exception = NULL;
    if (span->parent_id == 0U) {
        span->pid = getpid();
    }
    // Start time is nanoseconds from unix epoch
    // @see https://docs.datadoghq.com/api/?lang=python#send-traces
    span->start = _get_nanoseconds(USE_REALTIME_CLOCK);

    span->call = call;
    span->dispatch = dispatch;
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
