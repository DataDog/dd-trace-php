#ifndef DD_LOGGING_H
#define DD_LOGGING_H
#include <php.h>

#include "configuration.h"

inline void ddtrace_log_err(const char *message) {
#if PHP_VERSION_ID < 80000
    TSRMLS_FETCH();
    php_log_err((char *)message TSRMLS_CC);
#else
    php_log_err(message);
#endif
}

#define ddtrace_log_debugf(...)            \
    do {                                   \
        if (get_dd_trace_debug()) {        \
            ddtrace_log_errf(__VA_ARGS__); \
        }                                  \
    } while (0)

#define ddtrace_log_debug(message)    \
    do {                              \
        if (get_dd_trace_debug()) {   \
            ddtrace_log_err(message); \
        }                             \
    } while (0)

#define ddtrace_assert_log_debug(message) \
    do {                                  \
        const char *message_ = message;   \
        ZEND_ASSERT(0 && message_);       \
        ddtrace_log_debug(message_);      \
    } while (0)

void ddtrace_log_errf(const char *format, ...);

/* These are used by the background sender; use other functions from PHP thread.
 * {{{ */
void ddtrace_bgs_log_minit(void);
void ddtrace_bgs_log_rinit(char *error_log);
void ddtrace_bgs_log_mshutdown(void);

int ddtrace_bgs_logf(const char *fmt, ...);
/* variadic functions cannot be inlined; we use a macro to essentially inline
 * the part we care about: the early return */
#define ddtrace_bgs_logf(fmt, ...) (get_dd_trace_debug_curl_output() ? ddtrace_bgs_logf(fmt, __VA_ARGS__) : 0)
/* }}} */

#endif  // DD_LOGGING_H
