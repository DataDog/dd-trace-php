#include <php.h>

#include "coms.h"
#include "ddtrace.h"
#include "handlers_internal.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static void (*dd_pcntl_fork_handler)(INTERNAL_FUNCTION_PARAMETERS) = NULL;

ZEND_FUNCTION(ddtrace_pcntl_fork) {
    dd_pcntl_fork_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    if (Z_LVAL_P(return_value) == 0) {
        // CHILD PROCESS
        // Until we full support pcntl tracing:
        //   - kill the BGS
        //   - disable further tracing on the forked process (also ensures all spans are dropped)
        ddtrace_coms_kill_background_sender();
        ddtrace_disable_tracing_in_current_request();
    }
}

void ddtrace_pcntl_handlers_startup(void) {
    TSRMLS_FETCH();
    // If we cannot find ext/pcntl then do not hook the functions
    if (!zend_hash_exists(&module_registry, "pcntl", sizeof("pcntl") /* no - 1 */)) {
        return;
    }

    dd_zif_handler handlers[] = {
        {ZEND_STRL("pcntl_fork"), &dd_pcntl_fork_handler, ZEND_FN(ddtrace_pcntl_fork)},
    };
    size_t handlers_len = sizeof handlers / sizeof handlers[0];
    for (size_t i = 0; i < handlers_len; ++i) {
        dd_install_handler(handlers[i] TSRMLS_CC);
    }
}
