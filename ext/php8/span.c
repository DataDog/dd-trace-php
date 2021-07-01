#include "span.h"

#include <php.h>
#include <time.h>
#include <unistd.h>
#include <SAPI.h>

#include "auto_flush.h"
#include "configuration.h"
#include "ddtrace.h"
#include "dispatch.h"
#include "engine_hooks.h"
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

static uint64_t _get_nanoseconds(BOOL_T monotonic_clock) {
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

ddtrace_span_fci *ddtrace_init_span() {
    zval fci_zv;
    object_init_ex(&fci_zv, ddtrace_ce_span_data);
    ddtrace_span_fci *span_fci = (ddtrace_span_fci *)Z_OBJ(fci_zv);
    return span_fci;
}

void ddtrace_push_root_span() { ddtrace_open_span(ddtrace_init_span()); }

void dd_trace_stop_span_time(ddtrace_span_t *span) {
    span->duration = _get_nanoseconds(USE_MONOTONIC_CLOCK) - span->duration_start;
}

BOOL_T ddtrace_has_top_internal_span(ddtrace_span_fci *end) {
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

        if (get_dd_autofinish_spans()) {
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
    if (DDTRACE_G(span_ids_top) == NULL && get_dd_trace_auto_flush_enabled()) {
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

static zval *ensure_meta(zend_array *span) {
    zval *meta = zend_hash_str_find(span, ZEND_STRL("meta"));
    if (!meta) {
        zval meta_zv;
        array_init(&meta_zv);
        meta = zend_hash_str_add_new(span, ZEND_STRL("meta"), &meta_zv);
    }
    return meta;
}

static void trim_string_view(char **buf, int *len) {
    char *end = *buf + *len;
    while (*buf < end && (**buf == ' ' || **buf == '\t')) {
        ++*buf;
        --*len;
    }
    while (*len > 0 && ((*buf)[*len - 1] == ' ' || (*buf)[*len - 1] == '\t')) {
        --*len;
    }
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

    int status_code = SG(sapi_headers).http_response_code;
    if (status_code) {
        zval *meta = ensure_meta(Z_ARR_P(root));
        add_assoc_str(meta, "http.status_code", zend_strpprintf(0, "%d", status_code));

        zend_llist_position pos;
        zend_llist *header_list = &SG(sapi_headers).headers;
        for (sapi_header_struct *h = (sapi_header_struct *) zend_llist_get_first_ex(header_list, &pos); h; h = (sapi_header_struct*)zend_llist_get_next_ex(header_list, &pos)) {
            char *header_name = h->header;
            size_t name_len = 0;
            while (name_len < h->header_len && header_name[name_len] != ':') {
                ++name_len;
            }
            char *header_value = header_name + name_len + 1;
            size_t value_len = h->header_len - name_len - 1;
            if (0 < (ssize_t) value_len) {
                trim_string_view(&header_name, &name_len);
                trim_string_view(&header_value, &value_len);

                if (name_len == 0 || value_len == 0) {
                    continue;
                }

                char *lowercase_name = zend_str_tolower_dup(header_name, name_len);
                // TODO: check against DD_TRACE_HEADER_TAGS list, waiting for ZAI config
                for (int i = 0; i < name_len; ++i) {
                    char c = lowercase_name[i];
                    if (c < 'a' && c > 'z' && c < '0' && c > '9' && c != '_' && c != '-' && c != '/') {
                        lowercase_name[i] = '_';
                    }
                }

                zval value;
                zend_string *tag = zend_strpprintf(0, "http.response.headers.%.*s", (int) name_len, lowercase_name);
                ZVAL_STRINGL(&value, header_value, value_len);
                zend_hash_update(Z_ARR_P(meta), tag, &value);
                zend_string_release(tag);
                free(lowercase_name);
            }
        }
    }

    // Assign and clear out additional trace meta; re-initialize it to empty
    if (Z_TYPE(DDTRACE_G(additional_trace_meta)) == IS_ARRAY &&
        zend_hash_num_elements(Z_ARR_P(&DDTRACE_G(additional_trace_meta))) > 0) {
        zval *meta = ensure_meta(Z_ARR_P(root));
        zval *val;
        zend_long idx;
        zend_string *key;
        ZEND_HASH_FOREACH_KEY_VAL(Z_ARR(DDTRACE_G(additional_trace_meta)), idx, key, val) {
            Z_TRY_ADDREF_P(val);
            if (key) {
                zend_hash_update(Z_ARR_P(meta), key, val);
            } else {
                zend_hash_index_update(Z_ARR_P(meta), idx, val);
            }
        }
        ZEND_HASH_FOREACH_END();
    }
    zval_dtor(&DDTRACE_G(additional_trace_meta));
    array_init_size(&DDTRACE_G(additional_trace_meta), ddtrace_num_error_tags);
}
