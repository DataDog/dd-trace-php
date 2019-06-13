#include <php.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>

#if defined(__GLIBC__) || defined(__APPLE__)

#include <execinfo.h>
#include "backtrace.h"
#include "ddtrace.h"
#include "env_config.h"
#include "logging.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

struct _backtrace_data_t {
    BOOL_T handler_already_run;
    BOOL_T handler_installed;
};

static struct _backtrace_data_t backtrace_globals = {FALSE, FALSE};

void ddtrace_backtrace_handler(int sig) {
    if (backtrace_globals.handler_already_run) {
        exit(sig);
    }
    backtrace_globals.handler_already_run = TRUE;
    TSRMLS_FETCH();

    ddtrace_log_err("Datadog PHP Trace extension (DEBUG MODE)");
    ddtrace_log_errf("Received Signal %d", sig);
    void *array[MAX_STACK_SIZE];
    size_t size = backtrace(array, MAX_STACK_SIZE);
    if (size == MAX_STACK_SIZE) {
        ddtrace_log_err("Note: max stacktrace size reached");
    }

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

void ddtrace_install_backtrace_handler(TSRMLS_D) {
    if (!ddtrace_get_bool_config("DD_LOG_BACKTRACE", DDTRACE_G(log_backtrace))) {
        return;
    }

    static int handler_installed = 0;
    if (!handler_installed) {
        signal(SIGSEGV, ddtrace_backtrace_handler);
        backtrace_globals.handler_installed = TRUE;
    }
}
#else  // defined(__GLIBC__) || defined(__APPLE__)
#if defined(ZTS) && PHP_VERSION_ID < 70000
void ddtrace_install_backtrace_handler(TSRMLS_D) { (void)(TSRMLS_C); }
#else   // ZTS
void ddtrace_install_backtrace_handler() {
    // NOOP
}
#endif  // ZTS
#endif  // GLIBC
