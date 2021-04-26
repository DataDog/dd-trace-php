#include <php.h>
#include <stdbool.h>

#include "coms.h"
#include "configuration.h"
#include "engine_api.h"
#include "engine_hooks.h"  // for ddtrace_backup_error_handling
#include "handlers_internal.h"
#include "logging.h"
#include "span.h"

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

struct dd_pcntl_handler {
    const char *name;
    size_t name_len;
    void (**old_handler)(INTERNAL_FUNCTION_PARAMETERS);
    void (*new_handler)(INTERNAL_FUNCTION_PARAMETERS);
};
typedef struct dd_pcntl_handler dd_pcntl_handler;

static void dd_install_handler(dd_pcntl_handler handler TSRMLS_DC) {
    zend_function *old_handler;
    if (zend_hash_find(CG(function_table), handler.name, handler.name_len, (void **)&old_handler) == SUCCESS &&
        old_handler != NULL) {
        *handler.old_handler = old_handler->internal_function.handler;
        old_handler->internal_function.handler = handler.new_handler;
    }
}

void ddtrace_pcntl_handlers_startup(void) {
    TSRMLS_FETCH();
    // If we cannot find ext/pcntl then do not hook the functions
    if (!zend_hash_exists(&module_registry, "pcntl", sizeof("pcntl") /* no - 1 */)) {
        return;
    }

    /* We hook into pcntl_exec twice:
     *   - One that handles general dispatch so it will call the associated closure with pcntl_exec
     *   - One that handles the distributed tracing headers
     * The latter expects the former is already done because it needs a span id for the distributed tracing headers;
     * register them inside-out.
     */
    dd_pcntl_handler handlers[] = {
        {"pcntl_fork", sizeof("pcntl_fork"), &dd_pcntl_fork_handler, ZEND_FN(ddtrace_pcntl_fork)},
    };
    size_t handlers_len = sizeof handlers / sizeof handlers[0];
    for (size_t i = 0; i < handlers_len; ++i) {
        dd_install_handler(handlers[i] TSRMLS_CC);
    }
}
