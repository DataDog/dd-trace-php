#include "logging.h"

#include <php.h>

extern inline void ddtrace_log_err(char *message);

void _ddtrace_log_errf(const char *format, ...) {
    va_list args;
    char *buffer;

    va_start(args, format);
    vspprintf(&buffer, 0, format, args);
    ddtrace_log_err(buffer);

    efree(buffer);
    va_end(args);
}
