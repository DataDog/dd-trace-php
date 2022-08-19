#include "span.h"

#include <SAPI.h>
#include "priority_sampling/priority_sampling.h"
#include <interceptor/php8/interceptor.h>
#include <time.h>
#include <unistd.h>

#include "auto_flush.h"
#include "compat_string.h"
#include "configuration.h"
#include "ddtrace.h"
#include "logging.h"
#include "random.h"
#include "serializer.h"
#include "uri_normalization.h"

#define USE_REALTIME_CLOCK 0
#define USE_MONOTONIC_CLOCK 1

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void ddtrace_init_span_stacks(void) {
    DDTRACE_G(open_spans_top) = NULL;
    DDTRACE_G(closed_spans_top) = NULL;
    DDTRACE_G(open_spans_count) = 0;
    DDTRACE_G(dropped_spans_count) = 0;
    DDTRACE_G(closed_spans_count) = 0;
}

static void dd_drop_span(ddtrace_span_fci *span, bool silent) {
    span->span.duration = silent ? DDTRACE_SILENTLY_DROPPED_SPAN : DDTRACE_DROPPED_SPAN;
    span->next = NULL;
    OBJ_RELEASE(&span->span.std);
}

static void _free_span_stack(ddtrace_span_fci *span_fci, bool silent) {
    while (span_fci != NULL) {
        ddtrace_span_fci *tmp = span_fci;
        span_fci = tmp->next;
        dd_drop_span(tmp, silent);
    }
}

void ddtrace_free_span_stacks(bool silent) {
    _free_span_stack(DDTRACE_G(open_spans_top), silent);
    DDTRACE_G(open_spans_top) = NULL;
    _free_span_stack(DDTRACE_G(closed_spans_top), silent);
    DDTRACE_G(closed_spans_top) = NULL;
    DDTRACE_G(open_spans_count) = 0;
    DDTRACE_G(dropped_spans_count) = 0;
    DDTRACE_G(closed_spans_count) = 0;
}

static uint64_t _get_nanoseconds(bool monotonic_clock) {
    struct timespec time;
    if (clock_gettime(monotonic_clock ? CLOCK_MONOTONIC : CLOCK_REALTIME, &time) == 0) {
        return time.tv_sec * 1000000000L + time.tv_nsec;
    }
    return 0;
}

void ddtrace_open_span(ddtrace_span_fci *span_fci) {
    ddtrace_span_t *span = &span_fci->span;
    // Inherit from our current parent
    span->span_id = ddtrace_generate_span_id();
    span->parent_id = ddtrace_peek_span_id();
    span->trace_id = ddtrace_peek_trace_id();
    if (span->trace_id == 0) {
        span->trace_id = span->span_id;
    }
    span->duration_start = _get_nanoseconds(USE_MONOTONIC_CLOCK);
    // Start time is nanoseconds from unix epoch
    // @see https://docs.datadoghq.com/api/?lang=python#send-traces
    span->start = _get_nanoseconds(USE_REALTIME_CLOCK);

    span_fci->next = DDTRACE_G(open_spans_top);
    DDTRACE_G(open_spans_top) = span_fci;
    ++DDTRACE_G(open_spans_count);

    if (!span_fci->next) {  // root span
        span->chunk_root = span_fci;
        ddtrace_set_root_span_properties(&span_fci->span);
    } else {
        ddtrace_span_fci *next_span = span_fci->next;
        span->chunk_root = next_span->span.chunk_root;
        ZVAL_COPY(ddtrace_spandata_property_service(&span_fci->span),
                  ddtrace_spandata_property_service(&next_span->span));
        ZVAL_COPY(ddtrace_spandata_property_type(&span_fci->span), ddtrace_spandata_property_type(&next_span->span));
        ZVAL_OBJ_COPY(ddtrace_spandata_property_parent(&span_fci->span), &next_span->span.std);
    }
    ddtrace_set_global_span_properties(&span_fci->span);
}

ddtrace_span_fci *ddtrace_alloc_execute_data_span(zend_ulong index, zend_execute_data *execute_data) {
    zval *span_zv = zend_hash_index_find(&DDTRACE_G(traced_spans), index);
    ddtrace_span_fci *span_fci;
    if (span_zv) {
        span_fci = Z_PTR_P(span_zv);
        ++Z_TYPE_INFO_P(span_zv);
    } else {
        span_fci = ddtrace_init_span(DDTRACE_INTERNAL_SPAN);
        ddtrace_open_span(span_fci);

        GC_ADDREF(&span_fci->span.std);

        // SpanData::$name defaults to fully qualified called name
        zval *prop_name = ddtrace_spandata_property_name(&span_fci->span);

        if (EX(func) && EX(func)->common.function_name) {
            zval_ptr_dtor(prop_name);

            zend_class_entry *called_scope = EX(func)->common.scope ? zend_get_called_scope(execute_data) : NULL;
            if (called_scope) {
                // This cannot be cached on the dispatch since sub classes can share the same parent dispatch
                ZVAL_STR(prop_name, strpprintf(0, "%s.%s", ZSTR_VAL(called_scope->name), ZSTR_VAL(EX(func)->common.function_name)));
            } else {
                ZVAL_STR_COPY(prop_name, EX(func)->common.function_name);
            }
        }

        zval zv;
        Z_PTR(zv) = span_fci;
        Z_TYPE_INFO(zv) = 2;
        zend_hash_index_add_new(&DDTRACE_G(traced_spans), index, &zv);
    }
    return span_fci;
}

void ddtrace_clear_execute_data_span(zend_ulong index, bool keep) {
    zval *span_zv = zend_hash_index_find(&DDTRACE_G(traced_spans), index);
    if (--Z_TYPE_INFO_P(span_zv) == 1) {
        ddtrace_span_fci *span_fci = Z_PTR_P(span_zv);
        if (span_fci->span.duration != DDTRACE_DROPPED_SPAN && span_fci->span.duration != DDTRACE_SILENTLY_DROPPED_SPAN) {
            if (keep) {
                ddtrace_close_span(span_fci);
            } else {
                ddtrace_drop_top_open_span();
            }
        }
        OBJ_RELEASE(&span_fci->span.std);
        zend_hash_index_del(&DDTRACE_G(traced_spans), index);
    }
}

ddtrace_span_fci *ddtrace_init_span(enum ddtrace_span_type type) {
    zval fci_zv;
    object_init_ex(&fci_zv, ddtrace_ce_span_data);
    ddtrace_span_fci *span_fci = (ddtrace_span_fci *)Z_OBJ(fci_zv);
    span_fci->type = type;
    return span_fci;
}

void ddtrace_push_root_span(void) { ddtrace_open_span(ddtrace_init_span(DDTRACE_AUTOROOT_SPAN)); }

DDTRACE_PUBLIC bool ddtrace_root_span_add_tag(zend_string *tag, zval *value) {
    if (DDTRACE_G(open_spans_top) == NULL) {
        return false;
    }

    return zend_hash_add(ddtrace_spandata_property_meta(&DDTRACE_G(open_spans_top)->span.chunk_root->span), tag, value) != NULL;
}

bool ddtrace_span_alter_root_span_config(zval *old_value, zval *new_value) {
    if (Z_TYPE_P(old_value) == Z_TYPE_P(new_value) || DDTRACE_G(disable)) {
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
        if (span_fci->type != DDTRACE_USER_SPAN) {
            return false;
        }
        span_fci = span_fci->next;
    }
    return false;
}

void ddtrace_close_userland_spans_until(ddtrace_span_fci *until) {
    ddtrace_span_fci *span_fci;
    while ((span_fci = DDTRACE_G(open_spans_top)) && span_fci != until && span_fci->type != DDTRACE_AUTOROOT_SPAN) {
        if (span_fci->type == DDTRACE_INTERNAL_SPAN) {
            ddtrace_log_err("Found internal span data while closing userland spans");
        }

        zend_string *name = ddtrace_convert_to_str(ddtrace_spandata_property_name(&span_fci->span));
        ddtrace_log_debugf("Found unfinished span while automatically closing spans with name '%s'", ZSTR_VAL(name));
        zend_string_release(name);

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
    ++DDTRACE_G(closed_spans_count);
    --DDTRACE_G(open_spans_count);
    // TODO Assuming the tracing closure has run at this point, we can serialize the span onto a buffer with
    // ddtrace_coms_buffer_data() and free the span
    span_fci->next = DDTRACE_G(closed_spans_top);
    DDTRACE_G(closed_spans_top) = span_fci;

    if (span_fci->span.chunk_root == span_fci) {
        // Enforce a sampling decision here
        ddtrace_fetch_prioritySampling_from_root();

        // A userland span might still be open so we check the span ID stack instead of the internal span stack
        // In case we have root spans enabled, we need to always flush if we close that one (RSHUTDOWN)
        if (get_DD_TRACE_AUTO_FLUSH_ENABLED() && ddtrace_flush_tracer() == FAILURE) {
            ddtrace_log_debug("Unable to auto flush the tracer");
        }
    }
}

void ddtrace_close_all_open_spans(bool force_close_root_span) {
    ddtrace_span_fci *span_fci;
    while ((span_fci = DDTRACE_G(open_spans_top))) {
        if (get_DD_AUTOFINISH_SPANS() || (force_close_root_span && span_fci->type == DDTRACE_AUTOROOT_SPAN)) {
            dd_trace_stop_span_time(&span_fci->span);
            ddtrace_close_span(span_fci);
        } else {
            ddtrace_drop_top_open_span();
        }
    }
    DDTRACE_G(open_spans_top) = span_fci;
}

void ddtrace_drop_top_open_span(void) {
    ddtrace_span_fci *span_fci = DDTRACE_G(open_spans_top);
    if (span_fci == NULL) {
        return;
    }
    DDTRACE_G(open_spans_top) = span_fci->next;

    ++DDTRACE_G(dropped_spans_count);
    --DDTRACE_G(open_spans_count);

    dd_drop_span(span_fci, false);
}

void ddtrace_serialize_closed_spans(zval *serialized) {
    // The tracer supports only one trace per request so free any remaining open spans
    _free_span_stack(DDTRACE_G(open_spans_top), false);
    DDTRACE_G(open_spans_top) = NULL;
    DDTRACE_G(open_spans_count) = 0;
    DDTRACE_G(dropped_spans_count) = 0;
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
    if (!DDTRACE_G(distributed_parent_trace_id)) {
        DDTRACE_G(trace_id) = 0;
    }

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

zend_string *ddtrace_span_id_as_string(uint64_t id) { return zend_strpprintf(0, "%" PRIu64, id); }
