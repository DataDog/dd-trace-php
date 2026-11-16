#ifndef DD_TRACE_SOURCE_H
#define DD_TRACE_SOURCE_H

#include "ddtrace.h"

#define DD_P_TS_KEY "_dd.p.ts"

void ddtrace_trace_source_minit(void);
zend_string *ddtrace_trace_source_get_encoded(uint32_t source);
void ddtrace_trace_source_set_asm_source(void);
bool ddtrace_trace_source_is_trace_asm_sourced(zval *trace);
bool ddtrace_trace_source_is_asm_sourced(zend_array *attributes);

#endif  // DD_TRACE_SOURCE_H
