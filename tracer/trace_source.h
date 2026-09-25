#ifndef DD_TRACE_SOURCE_H
#define DD_TRACE_SOURCE_H

#include "ddtrace.h"

#define DD_P_TS_KEY "_dd.p.ts"

void ddtrace_trace_source_minit(void);
zend_string *ddtrace_trace_source_get_encoded(uint32_t source);
void ddtrace_trace_source_set_from_hexadecimal(zend_string *hexadecimal, zend_array *meta);
void ddtrace_trace_source_set_asm_source(void);
bool ddtrace_trace_source_is_trace_asm_sourced(zval *trace);
// Reads _dd.p.ts from `attributes` (may be NULL), then `meta`.
bool ddtrace_trace_source_is_asm_sourced(zend_array *attributes, zend_array *meta);

#endif  // DD_TRACE_SOURCE_H
