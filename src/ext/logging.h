#ifndef DD_LOGGING_H
#define DD_LOGGING_H
#include <php.h>

#include "configuration.h"

#define ddtrace_log_errf(...) _ddtrace_log_errf(__VA_ARGS__)
inline void ddtrace_log_err(char *message) {
    TSRMLS_FETCH();
    php_log_err(message TSRMLS_CC);
}

#define ddtrace_log_debugf(...)        \
    if (get_dd_trace_debug()) {        \
        ddtrace_log_errf(__VA_ARGS__); \
    }
#define ddtrace_log_debug(message) \
    if (get_dd_trace_debug()) {    \
        ddtrace_log_err(message);  \
    }

void _ddtrace_log_errf(const char *format, ...);

#endif  // DD_LOGGING_H
