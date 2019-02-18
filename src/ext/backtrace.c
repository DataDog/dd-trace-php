#include <execinfo.h>
#include <php.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>

#include "backtrace.h"
#include "ddtrace.h"
#include "env_config.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void ddtrace_backtrace_handler(int sig) {
    ddtrace_log_err("Datadog PHP Trace extension (DEBUG MODE)");
    ddtrace_log_errf("Received Signal %d", sig);
    void *array[MAX_STACK_SIZE];
    size_t size = backtrace(array, MAX_STACK_SIZE);

    ddtrace_log_err("Note: Backtrace below might be incomplete and have wrong entries due to optimized runtime");
    ddtrace_log_err("Backtrace:");

    char **backtraces = backtrace_symbols(array, size);
    if (backtraces) {
        size_t i;
        for (i = 0; i < size; i++) {
            ddtrace_log_err(backtraces[i]);
        }
        free(backtraces);
    }

    exit(sig);
}

void ddtrace_install_backtrace_handler() {
    if (!ddtrace_get_bool_config("DD_LOG_BACKTRACE", DDTRACE_G(log_backtrace))) {
        return;
    }

    static int handler_installed = 0;
    if (!handler_installed) {
        fflush(stderr);

        signal(SIGSEGV, ddtrace_backtrace_handler);
        handler_installed = 1;
    }
}
