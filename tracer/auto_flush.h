#ifndef DDTRACE_AUTO_FLUSH_H
#define DDTRACE_AUTO_FLUSH_H

#include <ext/datadog_export.h>
#include <php.h>
#include <stdbool.h>

ZEND_RESULT_CODE ddtrace_flush_tracer(bool force_on_startup, bool collect_cycles, bool fast_shutdown);

// This function is exported and used by appsec
DATADOG_PUBLIC void ddtrace_close_all_spans_and_flush(void);

#endif  // DDTRACE_AUTO_FLUSH_H
