#include "span.h"

#include <SAPI.h>
#include <inttypes.h>
#include <php5/priority_sampling/priority_sampling.h>
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
    DDTRACE_G(root_span) = NULL;
    DDTRACE_G(open_spans_count) = 0;
    DDTRACE_G(closed_spans_count) = 0;
}

static void _free_span_stack(ddtrace_span_fci *span_fci TSRMLS_DC) {
    while (span_fci != NULL) {
        ddtrace_span_fci *tmp = span_fci;
        span_fci = tmp->next;
        tmp->next = NULL;
        zend_objects_store_del_ref_by_handle(tmp->span.obj_value.handle TSRMLS_CC);
    }
}

void ddtrace_free_span_stacks(TSRMLS_D) {
    _free_span_stack(DDTRACE_G(open_spans_top) TSRMLS_CC);
    DDTRACE_G(open_spans_top) = NULL;
    DDTRACE_G(root_span) = NULL;
    _free_span_stack(DDTRACE_G(closed_spans_top) TSRMLS_CC);
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

void ddtrace_push_span(ddtrace_span_fci *span_fci TSRMLS_DC) {
    span_fci->next = DDTRACE_G(open_spans_top);
    DDTRACE_G(open_spans_top) = span_fci;
}

void ddtrace_open_span(ddtrace_span_fci *span_fci TSRMLS_DC) {
    ddtrace_push_span(span_fci TSRMLS_CC);

    ddtrace_span_t *span = &span_fci->span;
    // Peek at the active span ID before we push a new one onto the stack
    span->parent_id = ddtrace_peek_span_id(TSRMLS_C);
    span->span_id = ddtrace_push_span_id(0 TSRMLS_CC);
    // Set the trace_id last so we have ID's on the stack
    span->trace_id = DDTRACE_G(trace_id);
    span->duration_start = _get_nanoseconds(USE_MONOTONIC_CLOCK);
    // Start time is nanoseconds from unix epoch
    // @see https://docs.datadoghq.com/api/?lang=python#send-traces
    span->start = _get_nanoseconds(USE_REALTIME_CLOCK);

    if (!span_fci->next) {  // root span
        DDTRACE_G(root_span) = span_fci;
        ddtrace_set_root_span_properties(&span_fci->span TSRMLS_CC);
    } else {
        zval *last_service = ddtrace_spandata_property_service(&span_fci->next->span);
        if (last_service) {
            zval **service = ddtrace_spandata_property_service_write(&span_fci->span);
            MAKE_STD_ZVAL(*service);
            MAKE_COPY_ZVAL(&last_service, *service);
        }
        zval *last_type = ddtrace_spandata_property_type(&span_fci->next->span);
        if (last_type) {
            zval **type = ddtrace_spandata_property_type_write(&span_fci->span);
            MAKE_STD_ZVAL(*type);
            MAKE_COPY_ZVAL(&last_type, *type);
        }
        zval **parent = ddtrace_spandata_property_parent_write(&span_fci->span);
        MAKE_STD_ZVAL(*parent);
        Z_TYPE_PP(parent) = IS_OBJECT;
        Z_OBJVAL_PP(parent) = span_fci->next->span.obj_value;
        zend_objects_store_add_ref(*parent TSRMLS_CC);
    }
    ddtrace_set_global_span_properties(&span_fci->span TSRMLS_CC);
}

ddtrace_span_fci *ddtrace_init_span(TSRMLS_D) {
    zval fci_zv;
    object_init_ex(&fci_zv, ddtrace_ce_span_data);
    ddtrace_span_fci *span_fci = (ddtrace_span_fci *)zend_object_store_get_object(&fci_zv TSRMLS_CC);
    return span_fci;
}

void ddtrace_push_root_span(TSRMLS_D) { ddtrace_open_span(ddtrace_init_span(TSRMLS_C) TSRMLS_CC); }

bool ddtrace_span_alter_root_span_config(zval *old_value, zval *new_value) {
    TSRMLS_FETCH();

    if (Z_BVAL_P(old_value) == Z_BVAL_P(new_value) || DDTRACE_G(disable)) {
        return true;
    }

    if (Z_BVAL_P(old_value) == 0) {
        if (DDTRACE_G(open_spans_top) == NULL) {
            ddtrace_push_root_span(TSRMLS_C);
            return true;
        }
        return false;
    } else {
        if (DDTRACE_G(open_spans_top) == NULL) {
            return true;  // might be the case after serialization
        }
        if (DDTRACE_G(open_spans_top)->next == NULL && DDTRACE_G(closed_spans_top) == NULL) {
            ddtrace_drop_top_open_span(TSRMLS_C);
            return true;
        } else {
            return false;
        }
    }
}

void dd_trace_stop_span_time(ddtrace_span_t *span) {
    span->duration = _get_nanoseconds(USE_MONOTONIC_CLOCK) - span->duration_start;
}

bool ddtrace_has_top_internal_span(ddtrace_span_fci *end TSRMLS_DC) {
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

void ddtrace_close_userland_spans_until(ddtrace_span_fci *until TSRMLS_DC) {
    ddtrace_span_fci *span_fci;
    while ((span_fci = DDTRACE_G(open_spans_top)) && span_fci != until &&
           (span_fci->execute_data != NULL || span_fci->next)) {
        if (span_fci->execute_data) {
            ddtrace_log_err("Found internal span data while closing userland spans");
        }

        if (get_DD_AUTOFINISH_SPANS()) {
            dd_trace_stop_span_time(&span_fci->span);
            ddtrace_close_span(span_fci TSRMLS_CC);
        } else {
            ddtrace_drop_top_open_span(TSRMLS_C);
        }
    }
    DDTRACE_G(open_spans_top) = span_fci;
}

void ddtrace_close_span(ddtrace_span_fci *span_fci TSRMLS_DC) {
    if (span_fci == NULL || !ddtrace_has_top_internal_span(span_fci TSRMLS_CC)) {
        return;
    }

    ddtrace_close_userland_spans_until(span_fci TSRMLS_CC);

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

    if (DDTRACE_G(span_ids_top) == NULL) {
        // Enforce a sampling decision here
        ddtrace_fetch_prioritySampling_from_root(TSRMLS_C);

        DDTRACE_G(root_span) = NULL;

        // A userland span might still be open so we check the span ID stack instead of the internal span stack
        // In case we have root spans enabled, we need to always flush if we close that one (RSHUTDOWN)
        if (get_DD_TRACE_AUTO_FLUSH_ENABLED() && !ddtrace_flush_tracer(TSRMLS_C)) {
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
    zend_objects_store_del_ref_by_handle(span_fci->span.obj_value.handle TSRMLS_CC);
}

void ddtrace_serialize_closed_spans(zval *serialized TSRMLS_DC) {
    // The tracer supports only one trace per request so free any remaining open spans
    _free_span_stack(DDTRACE_G(open_spans_top) TSRMLS_CC);
    DDTRACE_G(root_span) = NULL;
    DDTRACE_G(open_spans_top) = NULL;
    DDTRACE_G(open_spans_count) = 0;
    ddtrace_free_span_id_stack(TSRMLS_C);
    ddtrace_span_fci *span_fci = DDTRACE_G(closed_spans_top);
    array_init(serialized);
    while (span_fci != NULL) {
        ddtrace_span_fci *tmp = span_fci;
        span_fci = tmp->next;
        ddtrace_serialize_span_to_array(tmp, serialized TSRMLS_CC);
        zend_objects_store_del_ref_by_handle(tmp->span.obj_value.handle TSRMLS_CC);
        // Move the stack down one as ddtrace_serialize_span_to_array() might do a long jump
        DDTRACE_G(closed_spans_top) = span_fci;
    }
    DDTRACE_G(closed_spans_top) = NULL;
    DDTRACE_G(closed_spans_count) = 0;
    // Reset the span ID stack and trace ID
    ddtrace_free_span_id_stack(TSRMLS_C);

    // root span is always first on the array
    HashPosition start;
    zend_hash_internal_pointer_reset_ex(Z_ARRVAL_P(serialized), &start);
    zval **root;
    if (zend_hash_get_current_data_ex(Z_ARRVAL_P(serialized), (void **)&root, &start) != SUCCESS) {
        return;
    }

    // Assign and clear out additional trace meta; re-initialize it to empty
    if (Z_TYPE(DDTRACE_G(additional_trace_meta)) == IS_ARRAY &&
        zend_hash_num_elements(Z_ARRVAL(DDTRACE_G(additional_trace_meta))) > 0) {
        zval **meta;
        if (zend_hash_find(Z_ARRVAL_PP(root), "meta", sizeof("meta"), (void **)&meta) == FAILURE) {
            zval *metazv;
            ALLOC_INIT_ZVAL(metazv);
            array_init(metazv);
            zend_hash_add(Z_ARRVAL_PP(root), "meta", sizeof("meta"), &metazv, sizeof(zval *), (void **)&meta);
        }

        char *key;
        uint key_len;
        int keytype;
        ulong num_key;
        zval **val;
        HashPosition pos;

        for (zend_hash_internal_pointer_reset_ex(Z_ARRVAL(DDTRACE_G(additional_trace_meta)), &pos);
             keytype = zend_hash_get_current_key_ex(Z_ARRVAL(DDTRACE_G(additional_trace_meta)), &key, &key_len,
                                                    &num_key, 0, &pos),
             zend_hash_get_current_data_ex(Z_ARRVAL(DDTRACE_G(additional_trace_meta)), (void **)&val, &pos) == SUCCESS;
             zend_hash_move_forward_ex(Z_ARRVAL(DDTRACE_G(additional_trace_meta)), &pos)) {
            int success;
            // let default serialization keys always take precendence
            if (keytype == HASH_KEY_IS_STRING) {
                success = zend_hash_add(Z_ARRVAL_PP(meta), key, key_len, (void **)val, sizeof(zval *), NULL);
            } else {
                success = _zend_hash_index_update_or_next_insert(Z_ARRVAL_PP(meta), num_key, (void **)val,
                                                                 sizeof(zval *), NULL, HASH_ADD ZEND_FILE_LINE_CC);
            }
            if (success) {
                zval_addref_p(*val);
            }
        }
    }
    zval_dtor(&DDTRACE_G(additional_trace_meta));
    array_init_size(&DDTRACE_G(additional_trace_meta), ddtrace_num_error_tags);
}

char *ddtrace_span_id_as_string(uint64_t id) {
    char *ret;
    spprintf(&ret, 0, "%" PRIu64, id);
    return ret;
}
