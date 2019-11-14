#include "signals.h"

#include "config.h"
#include "php_config.h"

#if HAVE_SIGACTION

#include <dogstatsd_client/client.h>
#include <php.h>
#include <signal.h>

#include "configuration.h"
#include "ddtrace.h"
#include "logging.h"
#include "version.h"

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

#define METRICS_CONST_TAGS "lang:php,lang_version:" PHP_VERSION ",tracer_version:" PHP_DDTRACE_VERSION
#define MAX_STACK_SIZE 1024

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static void ddtrace_sigsegv_handler(int sig) {
    TSRMLS_FETCH();
    if (!DDTRACE_G(backtrace_handler_already_run)) {
        DDTRACE_G(backtrace_handler_already_run) = TRUE;
#if HAVE_SIGACTION
        BOOL_T health_metrics_enabled = get_dd_trace_heath_metrics_enabled();

        if (health_metrics_enabled) {
            char *dogstatsd_host = get_dd_agent_host();
            char *dogstatsd_port = get_dd_dogstatsd_port();

            ddtrace_log_errf("Segmentation fault");

            // todo: extract buffer, host, port, and client to module global, so this is just a send
            char *buf = malloc(DOGSTATSD_CLIENT_RECOMMENDED_MAX_MESSAGE_SIZE);
            size_t len = DOGSTATSD_CLIENT_RECOMMENDED_MAX_MESSAGE_SIZE;

            dogstatsd_client client =
                dogstatsd_client_ctor(dogstatsd_host, dogstatsd_port, buf, len, METRICS_CONST_TAGS);
            dogstatsd_client_status status =
                dogstatsd_client_count(&client, "datadog.tracer.uncaught_exceptions", "1", "class:sigsegv");

            if (status == DOGSTATSD_CLIENT_OK) {
                ddtrace_log_errf("sigsegv health metric sent");
            }

            dogstatsd_client_dtor(&client);

            free(buf);
            free(dogstatsd_port);
            free(dogstatsd_host);
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

void ddtrace_signals_minit(TSRMLS_D) {
    BOOL_T install_handler = get_dd_trace_heath_metrics_enabled();

#if DDTRACE_HAVE_BACKTRACE
    install_handler |= get_dd_log_backtrace();
#endif

    DDTRACE_G(backtrace_handler_already_run) = FALSE;

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
