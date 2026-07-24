#ifndef DDTRACE_TRACE_CONTEXT_H
#define DDTRACE_TRACE_CONTEXT_H

#include <Zend/zend_types.h>
#include <stdint.h>

#define DDTRACE_TRACE_FLAG_SAMPLED UINT8_C(0x01)
#define DDTRACE_TRACE_FLAG_RANDOM UINT8_C(0x02)
#define DDTRACE_TRACE_FLAGS_SUPPORTED (DDTRACE_TRACE_FLAG_SAMPLED | DDTRACE_TRACE_FLAG_RANDOM)

static inline uint8_t ddtrace_compute_trace_flags(uint8_t retained_flags, zend_long sampling_priority) {
    uint8_t flags = retained_flags & DDTRACE_TRACE_FLAG_RANDOM;
    if (sampling_priority > 0) {
        flags |= DDTRACE_TRACE_FLAG_SAMPLED;
    }
    return flags;
}

#endif // DDTRACE_TRACE_CONTEXT_H
