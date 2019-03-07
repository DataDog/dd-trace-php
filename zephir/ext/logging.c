#include "logging.h"
#include <php.h>

void ddtrace_log_errf(const char *format TSRMLS_DC, ...) {
    va_list args;
    char *buffer;

    va_start(args, format);
    vspprintf(&buffer, 0, format, args);
    ddtrace_log_err(buffer TSRMLS_CC);

    efree(buffer);
    va_end(args);
}
