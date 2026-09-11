#ifndef DD_OTEL_SAMPLING_H
#define DD_OTEL_SAMPLING_H

#include <Zend/zend_smart_str.h>
#include <php.h>

#define DDTRACE_OTEL_MAX_VALUE_LEN 256

typedef struct {
    uint64_t random_value;
    uint64_t threshold;
    size_t unknown_fields_len;
    uint8_t random_value_len;
    uint8_t threshold_len;
    char unknown_fields[DDTRACE_OTEL_MAX_VALUE_LEN];
} ddtrace_otel_sampling_state;

void ddtrace_otel_sampling_parse(ddtrace_otel_sampling_state *state, const char *value, size_t value_len);
zend_string *ddtrace_otel_sampling_extract_tracestate(zend_string *tracestate, ddtrace_otel_sampling_state *state);
void ddtrace_otel_sampling_decide_probability(ddtrace_otel_sampling_state *state, uint64_t trace_id,
                                               zend_long sampling_priority, double sample_rate);
void ddtrace_otel_sampling_decide_non_probability(ddtrace_otel_sampling_state *state);
void ddtrace_otel_sampling_append_to_tracestate(smart_str *tracestate, const ddtrace_otel_sampling_state *state);

// Takes ownership of tracestate and applies the W3C byte and member limits.
zend_string* ddtrace_otel_sampling_limit_tracestate(zend_string* tracestate);

#endif  // DD_OTEL_SAMPLING_H
