#include "logging.h"

#include <php.h>

void ddtrace_log_err(char *message) {
    TSRMLS_FETCH();
    php_log_err(message TSRMLS_CC);
}

void _ddtrace_log_errf(const char *format, ...) {
    va_list args;
    char *buffer;

    va_start(args, format);
    vspprintf(&buffer, 0, format, args);
    TSRMLS_FETCH();
    php_log_err(buffer TSRMLS_CC);

    efree(buffer);
    va_end(args);
}
