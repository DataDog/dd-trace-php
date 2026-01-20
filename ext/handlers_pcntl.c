#include <php.h>
#include <stdbool.h>

#include <components-rs/ddtrace.h>

#ifndef _WIN32
#include "coms.h"
#endif
#include "ddtrace.h"
#include "span.h"
#include "configuration.h"
#include "random.h"
#include "sidecar.h"
#include "handlers_internal.h"  // For 'ddtrace_replace_internal_function'

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static zif_handler dd_pcntl_fork_handler = NULL;
#if PHP_VERSION_ID >= 80100
static zif_handler dd_pcntl_rfork_handler = NULL;
#endif
#if PHP_VERSION_ID >= 80200
static zif_handler dd_pcntl_forkx_handler = NULL;
#endif

#if defined(__SANITIZE_ADDRESS__) && !defined(_WIN32)
#define JOIN_BGS_BEFORE_FORK 1
#endif

static void dd_prefork() {
#if JOIN_BGS_BEFORE_FORK
    if (!get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        ddtrace_coms_flush_shutdown_writer_synchronous();
    }
#endif
}

static void dd_handle_fork(zval *return_value) {
    if (Z_LVAL_P(return_value) == 0) {
        // CHILD PROCESS
        dd_internal_handle_fork();
    } else {
#if JOIN_BGS_BEFORE_FORK
        if (!get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
            ddtrace_coms_restart_writer();
        }
#endif
    }
}

ZEND_FUNCTION(ddtrace_pcntl_fork) {
    dd_prefork();
    dd_pcntl_fork_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    dd_handle_fork(return_value);
}

#if PHP_VERSION_ID >= 80100
ZEND_FUNCTION(ddtrace_pcntl_rfork) {
    dd_prefork();
    dd_pcntl_rfork_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    dd_handle_fork(return_value);
}
#endif

#if PHP_VERSION_ID >= 80200
ZEND_FUNCTION(ddtrace_pcntl_forkx) {
    dd_prefork();
    dd_pcntl_forkx_handler(INTERNAL_FUNCTION_PARAM_PASSTHRU);
    dd_handle_fork(return_value);
}
#endif

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

    datadog_php_zif_handler handlers[] = {
        {ZEND_STRL("pcntl_fork"), &dd_pcntl_fork_handler, ZEND_FN(ddtrace_pcntl_fork)},
#if PHP_VERSION_ID >= 80100
        {ZEND_STRL("pcntl_rfork"), &dd_pcntl_rfork_handler, ZEND_FN(ddtrace_pcntl_rfork)},
#endif
#if PHP_VERSION_ID >= 80200
        {ZEND_STRL("pcntl_forkx"), &dd_pcntl_forkx_handler, ZEND_FN(ddtrace_pcntl_forkx)},
#endif
    };
    size_t handlers_len = sizeof handlers / sizeof handlers[0];
    for (size_t i = 0; i < handlers_len; ++i) {
        datadog_php_install_handler(handlers[i]);
    }
}
