#include "logging.h"

#include <stdio.h>
#include <string.h>

#include "configuration.h"

char *php_ini_error_log = NULL;

void ddtrace_bgs_log_minit(char *error_log) {
    if (!error_log || strcasecmp(error_log, "syslog") == 0 || strlen(error_log) == 0) {
        return;
    }

    /* Check if we can open the file for appending; if not we'll abandon logging.
     * Note that we do not keep the file open; if a log rotate happened then we
     * would be writing to the old log file. I believe there is a way to detect
     * this using fstat, but I'm going for a simple solution for now.
     */
    FILE *fh = fopen(error_log, "a");
    if (fh) {
        fclose(fh);
        php_ini_error_log = strdup(error_log);
    }
}

void ddtrace_bgs_log_mshutdown(void) { free(php_ini_error_log); }

#undef ddtrace_bgs_logf
int ddtrace_bgs_logf(const char *fmt, ...) {
    int ret = 0;
    va_list args;
    FILE *fh = fopen(php_ini_error_log, "a");

    if (fh) {
        va_start(args, fmt);
        ret = vfprintf(fh, fmt, args);
        va_end(args);
        fclose(fh);
    }

    return ret;
}

extern inline void ddtrace_log_err(char *message);

void ddtrace_log_errf(const char *format, ...) {
    va_list args;
    char *buffer;

    va_start(args, format);
    vspprintf(&buffer, 0, format, args);
    ddtrace_log_err(buffer);

    efree(buffer);
    va_end(args);
}
