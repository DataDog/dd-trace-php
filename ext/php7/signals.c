#include "signals.h"

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_config.h"

#if HAVE_SIGACTION

#include <dogstatsd_client/client.h>
#include <php.h>
#include <signal.h>

#include "configuration.h"
#include "ddtrace.h"
#include "ext/version.h"
#include "logging.h"

#if defined HAVE_EXECINFO_H && defined backtrace_size_t && defined HAVE_BACKTRACE
#define DDTRACE_HAVE_BACKTRACE 1
#else
#define DDTRACE_HAVE_BACKTRACE 0
#endif

#if DDTRACE_HAVE_BACKTRACE
#include <execinfo.h>
#endif

// true globals; only modify in MINIT/MSHUTDOWN
static stack_t ddtrace_altstack;
static struct sigaction ddtrace_sigaction;

#define MAX_STACK_SIZE 1024

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static void ddtrace_sigsegv_handler(int sig) {
    if (!DDTRACE_G(backtrace_handler_already_run)) {
        DDTRACE_G(backtrace_handler_already_run) = true;
        ddtrace_log_errf("Segmentation fault");

#if HAVE_SIGACTION
        bool health_metrics_enabled = get_DD_TRACE_HEALTH_METRICS_ENABLED();
        if (health_metrics_enabled) {
            dogstatsd_client *client = &DDTRACE_G(dogstatsd_client);
            const char *metric = "datadog.tracer.uncaught_exceptions";
            const char *tags = "class:sigsegv";
            dogstatsd_client_status status = dogstatsd_client_count(client, metric, "1", tags);

            if (status == DOGSTATSD_CLIENT_OK) {
                ddtrace_log_errf("sigsegv health metric sent");
            }
        }
#endif

#if DDTRACE_HAVE_BACKTRACE
        ddtrace_log_err("Datadog PHP Trace extension (DEBUG MODE)");
        ddtrace_log_errf("Received Signal %d", sig);
        void *array[MAX_STACK_SIZE];
        backtrace_size_t size = backtrace(array, MAX_STACK_SIZE);
        if (size == MAX_STACK_SIZE) {
            ddtrace_log_err("Note: max stacktrace size reached");
        }

        ddtrace_log_err("Note: Backtrace below might be incomplete and have wrong entries due to optimized runtime");
        ddtrace_log_err("Backtrace:");

        char **backtraces = backtrace_symbols(array, size);
        if (backtraces) {
            for (backtrace_size_t i = 0; i < size; i++) {
                ddtrace_log_err(backtraces[i]);
            }
            free(backtraces);
        }
#endif
    }

    exit(128 + sig);
}

void ddtrace_signals_first_rinit(void) {
    bool install_handler = get_DD_TRACE_HEALTH_METRICS_ENABLED();

#if DDTRACE_HAVE_BACKTRACE
    install_handler |= get_DD_LOG_BACKTRACE();
#endif

    DDTRACE_G(backtrace_handler_already_run) = false;

    /* Install a signal handler for SIGSEGV and run it on an alternate stack.
     * Using an alternate stack allows the handler to run even when the main
     * stack overflows.
     */
    if (install_handler) {
        if ((ddtrace_altstack.ss_sp = malloc(SIGSTKSZ))) {
            ddtrace_altstack.ss_size = SIGSTKSZ;
            ddtrace_altstack.ss_flags = 0;
            if (sigaltstack(&ddtrace_altstack, NULL) == 0) {
                ddtrace_sigaction.sa_flags = SA_ONSTACK;
                ddtrace_sigaction.sa_handler = ddtrace_sigsegv_handler;
                sigemptyset(&ddtrace_sigaction.sa_mask);
                sigaction(SIGSEGV, &ddtrace_sigaction, NULL);
            }
        }
    }
}

void ddtrace_signals_mshutdown(void) { free(ddtrace_altstack.ss_sp); }

#else
void ddtrace_signals_minit(void) {}
void ddtrace_signals_mshutdown(void) {}
#endif
