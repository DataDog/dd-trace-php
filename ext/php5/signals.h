#ifndef DD_TRACE_SIGNALS_H
#define DD_TRACE_SIGNALS_H

#include <TSRM/TSRM.h>

void ddtrace_signals_first_rinit(TSRMLS_D);
void ddtrace_signals_mshutdown(void);

#endif  // DD_TRACE_SIGNALS_H
