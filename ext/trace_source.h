#ifndef DD_TRACE_SOURCE_H
#define DD_TRACE_SOURCE_H

#include "ddtrace.h"

#define DD_P_TS_KEY "_dd.p.ts"

void ddtrace_trace_source_minit();
void ddtrace_trace_source_rinit();
zend_string *ddtrace_trace_source_get_ts_encoded();
void ddtrace_trace_source_set_from_string(zend_string *hexadecimal_string);
void ddtrace_trace_source_set_asm();
bool ddtrace_trace_source_is_asm_source();

#endif  // DD_TRACE_SOURCE_H
