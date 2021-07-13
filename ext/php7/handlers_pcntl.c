#include <php.h>
#include <stdbool.h>

#include "coms.h"
#include "ddtrace.h"
#include "engine_hooks.h"       // For 'ddtrace_resource'
#include "handlers_internal.h"  // For 'ddtrace_replace_internal_function'

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

/* This function is called during process startup so all of the memory allocations should be
 * persistent to avoid using the Zend Memory Manager. This will avoid an accidental use after free.
 *
 * "If you use ZendMM out of the scope of a request (like in MINIT()), the allocation will be
 * silently cleared by ZendMM before treating the first request, and you'll probably use-after-free:
 * simply don't."
 *
 * @see http://www.phpinternalsbook.com/php7/memory_management/zend_memory_manager.html#common-errors-and-mistakes
 */
void ddtrace_pcntl_handlers_startup(void) {
    // if we cannot find ext/pcntl then do not instrument it
    zend_string *pcntl = zend_string_init(ZEND_STRL("pcntl"), 1);
    bool pcntl_loaded = zend_hash_exists(&module_registry, pcntl);
    zend_string_release(pcntl);
    if (!pcntl_loaded) {
        return;
    }

    dd_zif_handler handlers[] = {
        {ZEND_STRL("pcntl_fork"), &dd_pcntl_fork_handler, ZEND_FN(ddtrace_pcntl_fork)},
    };
    size_t handlers_len = sizeof handlers / sizeof handlers[0];
    for (size_t i = 0; i < handlers_len; ++i) {
        dd_install_handler(handlers[i]);
    }

    if (ddtrace_resource != -1) {
        ddtrace_string pcntl_fork = DDTRACE_STRING_LITERAL("pcntl_fork");
        ddtrace_replace_internal_function(CG(function_table), pcntl_fork);
    }
}
