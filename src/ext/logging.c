#include "logging.h"

#include <stdio.h>
#include <string.h>
#include <time.h>

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
    FILE *fh = fopen(php_ini_error_log, "a");

    if (fh) {
        va_list args, args_copy;
        va_start(args, fmt);

        va_copy(args_copy, args);
        int needed_len = vsnprintf(NULL, 0, fmt, args_copy);
        va_end(args_copy);

        char *msgbuf = malloc(needed_len);
        vsnprintf(msgbuf, needed_len, fmt, args);
        va_end(args);

        time_t now;
        time(&now);
        struct tm *now_local = localtime(&now);
        // todo: we only need 20-ish for the main part, but how much for the timezone?
        // Wish PHP printed -hhmm or +hhmm instead of the name
        char timebuf[64];
        int time_len = strftime(timebuf, sizeof timebuf, "%d-%m-%Y %H:%m:%S %Z", now_local);
        if (time_len > 0) {
            ret = fprintf(fh, "[%s] %s\n", timebuf, msgbuf);
        }

        free(msgbuf);
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
