#ifndef DD_TRACE_SIGNALS_H
#define DD_TRACE_SIGNALS_H

#include <stdbool.h>

typedef struct ddog_SignalFlush ddog_SignalFlush;

void datadog_set_coredumpfilter(void);
void datadog_signals_first_rinit(void);
void datadog_signals_minit(void);
void datadog_signals_mshutdown(void);
bool datadog_signals_has_sidecar_flush(void);
// Always takes ownership of `flush`, including when it cannot be published.
// With `replace` false, publishes only when no flush object is installed. With
// `replace` true, replaces or clears an installed object unless a signal handler
// has already claimed it. Passing NULL while replacing clears an unclaimed stale
// object so setup can retry later.
void datadog_signals_set_sidecar_flush(ddog_SignalFlush *flush, bool replace);
void datadog_signals_reset_sidecar_flush_after_fork(void);

#endif  // DD_TRACE_SIGNALS_H
