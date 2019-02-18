#ifndef DD_LOGGING_H
#define DD_LOGGING_H

#define ddtrace_log_err(message) php_log_err(message)
void ddtrace_log_errf(const char *format, ...);

#endif  // DD_LOGGING_H
