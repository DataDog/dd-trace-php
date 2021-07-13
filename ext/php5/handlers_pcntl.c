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
        //   - disable further tracing on the forked process
        //   - enter 'drop all pending or new traces' mode
        //   - kill the BGS
        DDTRACE_G(disable_in_current_request) = 1;
        DDTRACE_G(drop_all_spans) = 1;
        ddtrace_coms_kill_background_sender();
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
