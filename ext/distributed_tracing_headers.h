#ifndef DD_DISTRIBUTED_TRACING_HEADERS_H
#define DD_DISTRIBUTED_TRACING_HEADERS_H

#include "ddtrace.h"
#include "priority_sampling/priority_sampling.h"
#include <zai_string/string.h>

typedef struct {
    ddtrace_trace_id trace_id;
    uint64_t parent_id;
    zend_string *origin;
    zend_string *tracestate;
    HashTable tracestate_unknown_dd_keys;
    HashTable propagated_tags;
    HashTable meta_tags;
    int priority_sampling;
    enum dd_sampling_mechanism sampling_mechanism;
    bool conflicting_sampling_priority; // propagated priority does not match tracestate priority
} ddtrace_distributed_tracing_result;

typedef bool (ddtrace_read_header)(zai_str zai_header, const char *lowercase_header, zend_string **header_value, void *data);
ddtrace_distributed_tracing_result ddtrace_read_distributed_tracing_ids(ddtrace_read_header *read_header, void *data);
void ddtrace_apply_distributed_tracing_result(ddtrace_distributed_tracing_result *result, ddtrace_root_span_data *span);
bool ddtrace_read_zai_header(zai_str zai_header, const char *lowercase_header, zend_string **header_value, void *data);
bool ddtrace_read_array_header(zai_str zai_header, const char *lowercase_header, zend_string **header_value, void *data);

#endif // DD_DISTRIBUTED_TRACING_HEADERS_H
