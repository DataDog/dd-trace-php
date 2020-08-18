#ifndef DD_TRACE_SAMPLER_H
#define DD_TRACE_SAMPLER_H
#include <php.h>

typedef struct _ddtrace_sample_entry {
    zend_string *filename;
    uint32_t lineno;
} ddtrace_sample_entry;

void ddtrace_sampler_rinit(void);
void ddtrace_serialize_samples(HashTable *serialized);
void ddtrace_sampler_rshutdown(void);

#endif  // DD_TRACE_SAMPLER_H
