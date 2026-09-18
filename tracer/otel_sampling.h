#ifndef DD_OTEL_SAMPLING_H
#define DD_OTEL_SAMPLING_H

#include <ext/compatibility.h>

#define DDTRACE_OTEL_MAX_VALUE_LEN 256

typedef struct {
    uint64_t random_value : 56;
    uint64_t random_value_len : 8;
    uint64_t threshold : 56;
    uint64_t threshold_len : 8;
    zend_string *unknown_fields; // Immutable, shared on copy; NULL when no unknown fields were received.
} ddtrace_otel_sampling_state;

static inline void ddtrace_otel_sampling_clear(ddtrace_otel_sampling_state *state) {
    if (state->unknown_fields) {
        zend_string_release(state->unknown_fields);
    }
    *state = (ddtrace_otel_sampling_state){0};
}

static inline void ddtrace_otel_sampling_copy(ddtrace_otel_sampling_state *dest, const ddtrace_otel_sampling_state *source) {
    ddtrace_otel_sampling_state copy = *source;
    if (copy.unknown_fields) {
        zend_string_addref(copy.unknown_fields);
    }
    ddtrace_otel_sampling_clear(dest);
    *dest = copy;
}

void ddtrace_otel_sampling_parse(ddtrace_otel_sampling_state *state, const char *value, size_t value_len);
zend_string *ddtrace_otel_sampling_extract_tracestate(zend_string *tracestate, ddtrace_otel_sampling_state *state);
void ddtrace_otel_sampling_decide_probability(ddtrace_otel_sampling_state *state, uint64_t trace_id, zend_long sampling_priority, double sample_rate);
void ddtrace_otel_sampling_decide_non_probability(ddtrace_otel_sampling_state *state);
void ddtrace_otel_sampling_append_to_tracestate(smart_str *tracestate, const ddtrace_otel_sampling_state *state);

// Takes ownership of tracestate and applies the W3C byte and member limits.
zend_string* ddtrace_otel_sampling_limit_tracestate(zend_string* tracestate);

#endif  // DD_OTEL_SAMPLING_H
