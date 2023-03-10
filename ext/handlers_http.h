#include "configuration.h"
#include "ddtrace.h"
#include "priority_sampling/priority_sampling.h"
#include "tracer_tag_propagation/tracer_tag_propagation.h"
#include "span.h"
#include <Zend/zend_smart_str.h>

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static inline void ddtrace_inject_distributed_headers_config(zend_array *array, bool key_value_pairs, zend_array *inject) {
    zval headers;
    ZVAL_ARR(&headers, array);

#define ADD_HEADER(header, ...) \
    if (key_value_pairs) { \
        add_assoc_str_ex(&headers, ZEND_STRL(header), zend_strpprintf(0, __VA_ARGS__)); \
    } else { \
        add_next_index_str(&headers, zend_strpprintf(0, header ": " __VA_ARGS__)); \
    }

    bool send_datadog = zend_hash_str_exists(inject, ZEND_STRL("datadog"));
    bool send_tracestate = zend_hash_str_exists(inject, ZEND_STRL("tracecontext"));
    bool send_b3 = zend_hash_str_exists(inject, ZEND_STRL("b3")) || zend_hash_str_exists(inject, ZEND_STRL("b3multi"));
    bool send_b3single = zend_hash_str_exists(inject, ZEND_STRL("b3 single header"));

    zend_long sampling_priority = ddtrace_fetch_prioritySampling_from_root();
    if (sampling_priority != DDTRACE_PRIORITY_SAMPLING_UNKNOWN) {
        if (send_datadog) {
            ADD_HEADER("x-datadog-sampling-priority", ZEND_LONG_FMT, sampling_priority);
        }
        if (send_b3) {
            if (sampling_priority <= 0) {
                ADD_HEADER("x-b3-sampled", "0");
            } else if (sampling_priority == PRIORITY_SAMPLING_USER_KEEP) {
                ADD_HEADER("x-b3-flags", "1");
            } else {
                ADD_HEADER("x-b3-sampled", "1");
            }
        }
    }
    zend_string *propagated_tags = ddtrace_format_propagated_tags();
    if (send_datadog || send_b3 || send_b3single) {
        if (propagated_tags) {
            ADD_HEADER("x-datadog-tags", "%s", ZSTR_VAL(propagated_tags));
        }
        if (DDTRACE_G(dd_origin)) {
            ADD_HEADER("x-datadog-origin", "%s", ZSTR_VAL(DDTRACE_G(dd_origin)));
        }
    }
    ddtrace_trace_id trace_id = ddtrace_peek_trace_id();
    uint64_t span_id = ddtrace_peek_span_id();
    if (trace_id.low || trace_id.high) {
        if (send_datadog) {
            ADD_HEADER("x-datadog-trace-id", "%" PRIu64, trace_id.low);
        }
        if (send_b3) {
            if (trace_id.high) {
                ADD_HEADER("X-B3-TraceId", "%016" PRIx64 "%016" PRIx64, trace_id.high, trace_id.low);
            } else {
                ADD_HEADER("X-B3-TraceId", "%016" PRIx64, trace_id.low);
            }
        }
        if (span_id) {
            if (send_datadog) {
                ADD_HEADER("x-datadog-parent-id", "%" PRIu64, span_id);
            }
            if (send_b3) {
                ADD_HEADER("X-B3-SpanId", "%016" PRIx64, span_id);
            }
            if (send_tracestate) {
                ADD_HEADER("traceparent", "00-%016" PRIx64 "%016" PRIx64 "-%016" PRIx64 "-%02" PRIx8,
                           trace_id.high,
                           trace_id.low,
                           span_id,
                           sampling_priority > 0);

                smart_str str = {0};

                if (DDTRACE_G(dd_origin)) {
                    smart_str_appends(&str, "o:");
                    signed char *cur = (signed char *)ZSTR_VAL(str.s) + ZSTR_LEN(str.s);
                    smart_str_append(&str, DDTRACE_G(dd_origin));
                    for (signed char *end = (signed char *)ZSTR_VAL(str.s) + ZSTR_LEN(str.s); cur < end; ++cur) {
                        if (*cur < 0x20 || *cur == ',' || *cur == ';' || *cur == '=') {
                            *cur = '_';
                        }
                    }
                }

                if (sampling_priority != DDTRACE_PRIORITY_SAMPLING_UNKNOWN && sampling_priority != 0 && sampling_priority != 1) {
                    if (str.s) {
                        smart_str_appendc(&str, ';');
                    }
                    smart_str_append_printf(&str, "s:" ZEND_LONG_FMT, sampling_priority);
                }

                if (propagated_tags) {
                    if (str.s) {
                        smart_str_appendc(&str, ';');
                    }
                    int last_separator = true;
                    char next_equals;
                    for (char *cur = ZSTR_VAL(propagated_tags), *end = cur + ZSTR_LEN(propagated_tags); cur < end; ++cur) {
                        if (last_separator) {
                            next_equals = ':';
                            cur += strlen("_dd.p");
                            smart_str_appendc(&str, 't');
                        }
                        signed char chr = *cur;
                        if (chr < 0x20  || chr == ';' || chr == '~') {
                            chr = '_';
                        } else if (chr == ',') {
                            chr = ';';
                        } else if (chr == '=') {
                            chr = next_equals;
                            next_equals = '~';
                        }
                        smart_str_appendc(&str, chr);
                        last_separator = chr == ';';
                    }
                }

                zend_string *unknown_key;
                zval *unknown_val;
                ZEND_HASH_FOREACH_STR_KEY_VAL(&DDTRACE_G(tracestate_unknown_dd_keys), unknown_key, unknown_val) {
                    if (str.s) {
                        smart_str_appendc(&str, ';');
                    }
                    smart_str_append(&str, unknown_key);
                    smart_str_appendc(&str, ':');
                    smart_str_append(&str, Z_STR_P(unknown_val));
                } ZEND_HASH_FOREACH_END();

                bool hasdd = str.s != NULL;
                if (DDTRACE_G(tracestate) && ZSTR_LEN(DDTRACE_G(tracestate)) > 0) {
                    if (str.s) {
                        smart_str_appendc(&str, ',');
                    }
                    smart_str_append(&str, DDTRACE_G(tracestate));
                }

                if (str.s) {
                    ADD_HEADER("tracestate", "%s%.*s", hasdd ? "dd=" : "", (int)ZSTR_LEN(str.s), ZSTR_VAL(str.s));
                    smart_str_free(&str);
                }
            }
        }
    }

    if (send_b3single) {
        char *b3_sampling_decision = NULL;
        if (sampling_priority != DDTRACE_PRIORITY_SAMPLING_UNKNOWN) {
            if (sampling_priority <= 0) {
                b3_sampling_decision = "0";
            } else if (sampling_priority == PRIORITY_SAMPLING_USER_KEEP) {
                b3_sampling_decision = "d";
            } else {
                b3_sampling_decision = "1";
            }
        }
        if ((trace_id.low || trace_id.high) && span_id) {
            char trace_id_buf[DD_TRACE_MAX_ID_LEN];
            if (trace_id.high) {
                sprintf(trace_id_buf, "%016" PRIx64 "%016" PRIx64, trace_id.high, trace_id.low);
            } else {
                sprintf(trace_id_buf, "%016" PRIx64, trace_id.low);
            }
            ADD_HEADER("b3", "%s-%016" PRIx64 "%s%s",
                       trace_id_buf,
                       span_id,
                       b3_sampling_decision ? "-" : "", b3_sampling_decision ? b3_sampling_decision : "");
        } else if (b3_sampling_decision) {
            ADD_HEADER("b3", "%s", b3_sampling_decision);
        }
    }

    if (propagated_tags) {
        zend_string_release(propagated_tags);
    }

#undef ADD_HEADER
}

static inline void ddtrace_inject_distributed_headers(zend_array *array, bool key_value_pairs) {
    zend_array *inject = zai_config_is_modified(DDTRACE_CONFIG_DD_TRACE_PROPAGATION_STYLE)
                         && !zai_config_is_modified(DDTRACE_CONFIG_DD_TRACE_PROPAGATION_STYLE_INJECT)
                         ? get_DD_TRACE_PROPAGATION_STYLE() : get_DD_TRACE_PROPAGATION_STYLE_INJECT();
    ddtrace_inject_distributed_headers_config(array, key_value_pairs, inject);
}
