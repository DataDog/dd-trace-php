#include "configuration.h"
#include "ddtrace.h"
#include "priority_sampling/priority_sampling.h"
#include "tracer_tag_propagation/tracer_tag_propagation.h"
#include "span.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static inline void ddtrace_inject_distributed_headers(zend_array *array) {
    zval headers;
    ZVAL_ARR(&headers, array);

    bool send_datadog = zend_hash_str_exists(get_DD_PROPAGATION_STYLE_INJECT(), ZEND_STRL("Datadog"));
    bool send_b3 = zend_hash_str_exists(get_DD_PROPAGATION_STYLE_INJECT(), ZEND_STRL("B3"));

    zend_long sampling_priority = ddtrace_fetch_prioritySampling_from_root();
    if (sampling_priority != DDTRACE_PRIORITY_SAMPLING_UNKNOWN) {
        if (send_datadog) {
            add_next_index_str(&headers,
                               zend_strpprintf(0, "x-datadog-sampling-priority: " ZEND_LONG_FMT, sampling_priority));
        }
        if (send_b3) {
            if (sampling_priority <= 0) {
                add_next_index_string(&headers, "X-B3-Sampled: 0");
            } else if (sampling_priority == PRIORITY_SAMPLING_USER_KEEP) {
                add_next_index_string(&headers, "X-B3-Flags: 1");
            } else {
                add_next_index_string(&headers, "X-B3-Sampled: 1");
            }
        }
    }
    zend_string *propagated_tags = ddtrace_format_propagated_tags();
    if (propagated_tags) {
        add_next_index_str(&headers, zend_strpprintf(0, "x-datadog-tags: %s", ZSTR_VAL(propagated_tags)));
        zend_string_release(propagated_tags);
    }
    uint64_t trace_id = ddtrace_peek_trace_id(), span_id = ddtrace_peek_span_id();
    if (trace_id) {
        if (send_datadog) {
            add_next_index_str(&headers, zend_strpprintf(0, "x-datadog-trace-id: %" PRIu64, trace_id));
        }
        if (send_b3) {
            add_next_index_str(&headers, zend_strpprintf(0, "X-B3-TraceId: %" PRIx64, trace_id));
        }
        if (span_id) {
            if (send_datadog) {
                add_next_index_str(&headers, zend_strpprintf(0, "x-datadog-parent-id: %" PRIu64, span_id));
            }
            if (send_b3) {
                add_next_index_str(&headers, zend_strpprintf(0, "X-B3-SpanId: %" PRIx64, span_id));
            }
        }
    }
    if (DDTRACE_G(dd_origin)) {
        add_next_index_str(&headers, zend_strpprintf(0, "x-datadog-origin: %s", ZSTR_VAL(DDTRACE_G(dd_origin))));
    }

    if (zend_hash_str_exists(get_DD_PROPAGATION_STYLE_INJECT(), ZEND_STRL("B3 single header"))) {
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
        if (trace_id && span_id) {
            add_next_index_str(&headers, zend_strpprintf(0, "b3: %" PRIx64 "-%" PRIx64 "%s%s", trace_id, span_id, b3_sampling_decision ? "-" : "", b3_sampling_decision ? b3_sampling_decision : ""));
        } else if (b3_sampling_decision) {
            add_next_index_str(&headers, zend_strpprintf(0, "b3: %s", b3_sampling_decision));
        }
    }
}
