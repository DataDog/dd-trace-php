#ifndef DD_TRACE_SOURCE_H
#define DD_TRACE_SOURCE_H

#include "ddtrace.h"

#define DD_P_TS_KEY "_dd.p.ts"

void ddtrace_trace_source_minit();
zend_string *ddtrace_trace_source_get_encoded();
void ddtrace_trace_source_set_from_hexadecimal(zend_string *hexadecimal, zend_array *meta);
void ddtrace_trace_source_set_asm_source();
bool ddtrace_trace_source_is_trace_asm_sourced(zval *trace);
bool ddtrace_trace_source_is_meta_asm_sourced(zend_array *meta);

#endif  // DD_TRACE_SOURCE_H
