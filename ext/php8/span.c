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

void ddtrace_init_span_stacks(void) {
    DDTRACE_G(open_spans_top) = NULL;
    DDTRACE_G(closed_spans_top) = NULL;
    DDTRACE_G(open_spans_count) = 0;
    DDTRACE_G(closed_spans_count) = 0;
}

static void _free_span_stack(ddtrace_span_fci *span_fci) {
    while (span_fci != NULL) {
        ddtrace_span_fci *tmp = span_fci;
        span_fci = tmp->next;
        tmp->next = NULL;
        OBJ_RELEASE(&tmp->span.std);
    }
}

void ddtrace_free_span_stacks(void) {
    _free_span_stack(DDTRACE_G(open_spans_top));
    DDTRACE_G(open_spans_top) = NULL;
    _free_span_stack(DDTRACE_G(closed_spans_top));
    DDTRACE_G(closed_spans_top) = NULL;
    DDTRACE_G(open_spans_count) = 0;
    DDTRACE_G(closed_spans_count) = 0;
}

static uint64_t _get_nanoseconds(bool monotonic_clock) {
    struct timespec time;
    if (clock_gettime(monotonic_clock ? CLOCK_MONOTONIC : CLOCK_REALTIME, &time) == 0) {
        return time.tv_sec * 1000000000L + time.tv_nsec;
    }
    return 0;
}

void ddtrace_push_span(ddtrace_span_fci *span_fci) {
    span_fci->next = DDTRACE_G(open_spans_top);
    DDTRACE_G(open_spans_top) = span_fci;
}

void ddtrace_open_span(ddtrace_span_fci *span_fci) {
    ddtrace_push_span(span_fci);

    ddtrace_span_t *span = &span_fci->span;
    // Peek at the active span ID before we push a new one onto the stack
    span->parent_id = ddtrace_peek_span_id();
    span->span_id = ddtrace_push_span_id(0);
    // Set the trace_id last so we have ID's on the stack
    span->trace_id = DDTRACE_G(trace_id);
    span->duration_start = _get_nanoseconds(USE_MONOTONIC_CLOCK);
    // Start time is nanoseconds from unix epoch
    // @see https://docs.datadoghq.com/api/?lang=python#send-traces
    span->start = _get_nanoseconds(USE_REALTIME_CLOCK);
}

ddtrace_span_fci *ddtrace_init_span(void) {
    zval fci_zv;
    object_init_ex(&fci_zv, ddtrace_ce_span_data);
    ddtrace_span_fci *span_fci = (ddtrace_span_fci *)Z_OBJ(fci_zv);
    return span_fci;
}

void ddtrace_push_root_span(void) { ddtrace_open_span(ddtrace_init_span()); }

bool ddtrace_span_alter_root_span_config(zval *old_value, zval *new_value) {
    if (Z_TYPE_P(old_value) == Z_TYPE_P(new_value)) {
        return true;
    }

    if (Z_TYPE_P(old_value) == IS_FALSE) {
        if (DDTRACE_G(open_spans_top) == NULL) {
            ddtrace_push_root_span();
            return true;
        }
        return false;
    } else {
        if (DDTRACE_G(open_spans_top) == NULL) {
            return true;  // might be the case after serialization
        }
        if (DDTRACE_G(open_spans_top)->next == NULL && DDTRACE_G(closed_spans_top) == NULL) {
            ddtrace_drop_top_open_span();
            return true;
        } else {
            return false;
        }
    }
}

void dd_trace_stop_span_time(ddtrace_span_t *span) {
    span->duration = _get_nanoseconds(USE_MONOTONIC_CLOCK) - span->duration_start;
}

bool ddtrace_has_top_internal_span(ddtrace_span_fci *end) {
    ddtrace_span_fci *span_fci = DDTRACE_G(open_spans_top);
    while (span_fci) {
        if (span_fci == end) {
            return true;
        }
        if (span_fci->execute_data != NULL) {
            return false;
        }
        span_fci = span_fci->next;
    }
    return false;
}

void ddtrace_close_userland_spans_until(ddtrace_span_fci *until) {
    ddtrace_span_fci *span_fci;
    while ((span_fci = DDTRACE_G(open_spans_top)) && span_fci != until &&
           (span_fci->execute_data != NULL || span_fci->next)) {
        if (span_fci->execute_data) {
            ddtrace_log_err("Found internal span data while closing userland spans");
        }

        if (get_DD_AUTOFINISH_SPANS()) {
            dd_trace_stop_span_time(&span_fci->span);
            ddtrace_close_span(span_fci);
        } else {
            ddtrace_drop_top_open_span();
        }
    }
    DDTRACE_G(open_spans_top) = span_fci;
}

void ddtrace_close_span(ddtrace_span_fci *span_fci) {
    if (span_fci == NULL || !ddtrace_has_top_internal_span(span_fci)) {
        return;
    }

    ddtrace_close_userland_spans_until(span_fci);

    DDTRACE_G(open_spans_top) = span_fci->next;
    // Sync with span ID stack
    ddtrace_pop_span_id();
    // TODO Assuming the tracing closure has run at this point, we can serialize the span onto a buffer with
    // ddtrace_coms_buffer_data() and free the span
    span_fci->next = DDTRACE_G(closed_spans_top);
    DDTRACE_G(closed_spans_top) = span_fci;

    if (span_fci->dispatch) {
        ddtrace_dispatch_release(span_fci->dispatch);
        span_fci->dispatch = NULL;
    }

    // A userland span might still be open so we check the span ID stack instead of the internal span stack
    // In case we have root spans enabled, we need to always flush if we close that one (RSHUTDOWN)
    if (DDTRACE_G(span_ids_top) == NULL && get_DD_TRACE_AUTO_FLUSH_ENABLED()) {
        if (ddtrace_flush_tracer() == FAILURE) {
            ddtrace_log_debug("Unable to auto flush the tracer");
        }
    }
}

void ddtrace_drop_top_open_span(void) {
    ddtrace_span_fci *span_fci = DDTRACE_G(open_spans_top);
    if (span_fci == NULL) {
        return;
    }
    DDTRACE_G(open_spans_top) = span_fci->next;
    // Sync with span ID stack
    ddtrace_pop_span_id();
    OBJ_RELEASE(&span_fci->span.std);
}

void ddtrace_serialize_closed_spans(zval *serialized) {
    // The tracer supports only one trace per request so free any remaining open spans
    _free_span_stack(DDTRACE_G(open_spans_top));
    DDTRACE_G(open_spans_top) = NULL;
    DDTRACE_G(open_spans_count) = 0;
    ddtrace_free_span_id_stack();
    ddtrace_span_fci *span_fci = DDTRACE_G(closed_spans_top);
    array_init(serialized);
    while (span_fci != NULL) {
        ddtrace_span_fci *tmp = span_fci;
        span_fci = tmp->next;
        ddtrace_serialize_span_to_array(tmp, serialized);
        OBJ_RELEASE(&tmp->span.std);
        // Move the stack down one as ddtrace_serialize_span_to_array() might do a long jump
        DDTRACE_G(closed_spans_top) = span_fci;
    }
    DDTRACE_G(closed_spans_top) = NULL;
    DDTRACE_G(closed_spans_count) = 0;
    // Reset the span ID stack and trace ID
    ddtrace_free_span_id_stack();

    // root span is always first on the array
    HashPosition start;
    zend_hash_internal_pointer_reset_ex(Z_ARR_P(serialized), &start);
    zval *root = zend_hash_get_current_data_ex(Z_ARR_P(serialized), &start);
    if (!root) {
        return;
    }

    // Assign and clear out additional trace meta; re-initialize it to empty
    if (Z_TYPE(DDTRACE_G(additional_trace_meta)) == IS_ARRAY &&
        zend_hash_num_elements(Z_ARR_P(&DDTRACE_G(additional_trace_meta))) > 0) {
        zval *meta = zend_hash_str_find(Z_ARR_P(root), ZEND_STRL("meta"));
        if (!meta) {
            zval meta_zv;
            array_init(&meta_zv);
            meta = zend_hash_str_add_new(Z_ARR_P(root), ZEND_STRL("meta"), &meta_zv);
        }

        zval *val;
        zend_ulong idx;
        zend_string *key;
        ZEND_HASH_FOREACH_KEY_VAL(Z_ARR(DDTRACE_G(additional_trace_meta)), idx, key, val) {
            zval *added;
            // let default serialization keys always take precendence
            if (key) {
                added = zend_hash_add(Z_ARR_P(meta), key, val);
            } else {
                added = zend_hash_index_add(Z_ARR_P(meta), idx, val);
            }
            if (added) {
                Z_TRY_ADDREF_P(val);
            }
        }
        ZEND_HASH_FOREACH_END();
    }
    zval_dtor(&DDTRACE_G(additional_trace_meta));
    array_init_size(&DDTRACE_G(additional_trace_meta), ddtrace_num_error_tags);
}
