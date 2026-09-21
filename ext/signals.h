#ifndef DD_TRACE_SIGNALS_H
#define DD_TRACE_SIGNALS_H

#include <stdbool.h>

typedef struct ddog_SignalFlush ddog_SignalFlush;

void datadog_set_coredumpfilter(void);
void datadog_signals_first_rinit(void);
void datadog_signals_minit(void);
void datadog_signals_mshutdown(void);
bool datadog_signals_has_sidecar_flush(void);
void datadog_signals_set_sidecar_flush(ddog_SignalFlush *flush);
void datadog_signals_reset_sidecar_flush_after_fork(void);

#endif  // DD_TRACE_SIGNALS_H
