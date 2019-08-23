#ifndef DD_LOGGING_H
#define DD_LOGGING_H
#include <php.h>

#include "configuration.h"

#if defined(ZTS) && PHP_VERSION_ID < 70000
#define TSRMLS_FC TSRMLS_D,
#define ddtrace_log_errf(...) _ddtrace_log_errf(TSRMLS_C, __VA_ARGS__)
#else
#define TSRMLS_FC
#define ddtrace_log_errf(...) _ddtrace_log_errf(__VA_ARGS__)
#endif

#define ddtrace_log_err(message) php_log_err(message TSRMLS_CC)
#define ddtrace_log_debug(message) \
    if (get_dd_trace_debug()) {    \
        ddtrace_log_err(message);  \
    }
void _ddtrace_log_errf(TSRMLS_FC const char *format, ...);

#endif  // DD_LOGGING_H
