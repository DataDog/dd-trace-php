#ifndef DD_LOGGING_H
#define DD_LOGGING_H
#include <php.h>

#include "configuration.h"
#ifndef _WIN32
#include <stdatomic.h>
#else
#include <components/atomic_win32_polyfill.h>
#endif

extern _Atomic(int) datadog_error_log_fd;

/* These are used by the background sender; use other log component from PHP thread.
 * {{{ */
void datadog_log_minit(void);
void datadog_log_ginit(void);
void datadog_log_rinit(char *error_log);
void datadog_log_mshutdown(void);
int datadog_get_fd_path(int fd, char *buf);

int datadog_signal_safe_logf(const char *fmt, ...);

void datadog_log_init(void);
bool datadog_alter_dd_trace_debug(zval *old_value, zval *new_value, zend_string *new_str);
bool datadog_alter_dd_trace_log_level(zval *old_value, zval *new_value, zend_string *new_str);

#endif  // DD_LOGGING_H
