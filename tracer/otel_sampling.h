#ifndef DD_OTEL_SAMPLING_H
#define DD_OTEL_SAMPLING_H

#include "ddtrace.h"
#include "priority_sampling/priority_sampling.h"

// Takes ownership of tracestate and returns the normalized replacement.
zend_string* ddtrace_otel_sampling_update_tracestate(zend_string* tracestate, uint64_t trace_id, zend_long sampling_priority,
                                                     enum ddtrace_otel_sampling_decision decision, double sample_rate);

#endif  // DD_OTEL_SAMPLING_H
