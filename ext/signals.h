#ifndef DD_TRACE_SIGNALS_H
#define DD_TRACE_SIGNALS_H

void datadog_set_coredumpfilter(void);
void datadog_signals_first_rinit(void);
void datadog_signals_minit(void);
void datadog_signals_mshutdown(void);

#endif  // DD_TRACE_SIGNALS_H
