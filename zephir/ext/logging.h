#ifndef DD_LOGGING_H
#define DD_LOGGING_H
#include <php.h>

#define ddtrace_log_err(message) php_log_err(message)

void ddtrace_log_errf(const char *format TSRMLS_DC, ...);

#endif  // DD_LOGGING_H
