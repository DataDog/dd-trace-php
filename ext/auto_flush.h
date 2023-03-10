#ifndef DDTRACE_AUTO_FLUSH_H
#define DDTRACE_AUTO_FLUSH_H

#include <ddtrace_export.h>
#include <php.h>
#include <stdbool.h>

ZEND_RESULT_CODE ddtrace_flush_tracer(bool force_on_startup);

// This function is exported and used by appsec
DDTRACE_PUBLIC void ddtrace_close_all_spans_and_flush(void);

#endif  // DDTRACE_AUTO_FLUSH_H
