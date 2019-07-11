#include "logging.h"

#include <php.h>

void _ddtrace_log_errf(TSRMLS_FC const char *format, ...) {
    va_list args;
    char *buffer;

    va_start(args, format);
    vspprintf(&buffer, 0, format, args);
    ddtrace_log_err(buffer);

    efree(buffer);
    va_end(args);
}
