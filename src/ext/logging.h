#ifndef DD_LOGGING_H
#define DD_LOGGING_H
#include <php.h>

#include "configuration.h"

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

void ddtrace_log_errf(const char *format, ...);

/* These are used by the background sender; use other functions from PHP thread.
 * {{{ */
extern char *php_ini_error_log;
void ddtrace_bgs_log_minit(char *error_log);
void ddtrace_bgs_log_mshutdown(void);
int ddtrace_bgs_logf(const char *fmt, ...);
/* }}} */

#endif  // DD_LOGGING_H
