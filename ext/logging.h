#ifndef DD_LOGGING_H
#define DD_LOGGING_H
#include <php.h>

#include "configuration.h"
#ifndef _WIN32
#include <stdatomic.h>
#else
#include <components/atomic_win32_polyfill.h>
#endif

extern _Atomic(int) ddtrace_error_log_fd;

/* These are used by the background sender; use other log component from PHP thread.
 * {{{ */
void ddtrace_log_minit(void);
void ddtrace_log_ginit(void);
void ddtrace_log_rinit(char *error_log);
void ddtrace_log_mshutdown(void);
int ddtrace_get_fd_path(int fd, char *buf);

int ddtrace_bgs_logf(const char *fmt, ...);
/* variadic functions cannot be inlined; we use a macro to essentially inline
 * the part we care about: the early return */
#define ddtrace_bgs_logf(fmt, ...) (get_global_DD_TRACE_DEBUG_CURL_OUTPUT() ? ddtrace_bgs_logf(fmt, __VA_ARGS__) : 0)
/* }}} */

void ddtrace_log_init(void);
bool ddtrace_alter_dd_trace_debug(zval *old_value, zval *new_value, zend_string *new_str);
bool ddtrace_alter_dd_trace_log_level(zval *old_value, zval *new_value, zend_string *new_str);

#endif  // DD_LOGGING_H
