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

static bool dd_master_listener_was_active = false;

static void dd_prefork() {
#if JOIN_BGS_BEFORE_FORK
    if (!get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        ddtrace_coms_flush_shutdown_writer_synchronous();
    }
#endif

#ifndef _WIN32
    // Check if master listener is active before fork
    dd_master_listener_was_active = (ddtrace_master_pid != 0 && getpid() == ddtrace_master_pid);

    if (dd_master_listener_was_active) {
        // Shutdown master listener before fork to avoid Tokio runtime corruption.
        // Tokio's async runtime and fork() are fundamentally incompatible - forking
        // while Tokio threads are active causes state corruption in the parent process.
        // See: https://github.com/tokio-rs/tokio/issues/4301

        // First close parent's worker connection to prevent handler thread deadlock
        if (ddtrace_sidecar) {
            ddog_sidecar_transport_drop(ddtrace_sidecar);
            ddtrace_sidecar = NULL;
        }

        // Then shutdown the master listener and its Tokio runtime
        ddog_sidecar_shutdown_master_listener();
    }
#endif
}

static void dd_postfork_parent() {
#ifndef _WIN32
    // Restart master listener in parent if it was active before fork
    if (dd_master_listener_was_active) {
        // Reinitialize master listener with fresh Tokio runtime.
        // This recreates the async runtime that was shut down before fork.

        // First, restart the master listener thread
        if (!ddtrace_ffi_try("Failed restarting sidecar master listener after fork",
                             ddog_sidecar_connect_master((int32_t)ddtrace_master_pid))) {
            LOG(WARN, "Failed to restart sidecar master listener after fork");
            dd_master_listener_was_active = false;
            return;
        }

        // Then reconnect to it as a worker
        ddog_SidecarTransport *sidecar_transport = NULL;
        if (!ddtrace_ffi_try("Failed reconnecting master to sidecar after fork",
                             ddog_sidecar_connect_worker((int32_t)ddtrace_master_pid, &sidecar_transport))) {
            LOG(WARN, "Failed to reconnect master process to sidecar after fork");
            dd_master_listener_was_active = false;
            return;
        }

        ddtrace_sidecar = sidecar_transport;
        dd_master_listener_was_active = false;
    }
#endif
}

// Declare LSAN runtime interface functions
#if defined(__SANITIZE_ADDRESS__) && !defined(_WIN32)
void __lsan_disable(void);
void __lsan_enable(void);
#endif

static void dd_handle_fork(zval *return_value) {
    if (Z_LVAL_P(return_value) == 0) {
        // CHILD PROCESS
        // Disable ASAN leak detection in child to avoid false positives from inherited
        // Tokio TLS and runtime state that can't be properly cleaned up after fork
#if defined(__SANITIZE_ADDRESS__) && !defined(_WIN32)
        // The child inherits Tokio runtime thread-local storage from the parent's
        // master listener and connection handlers. These TLS destructors reference
        // threads that don't exist in the child, causing spurious leak reports.
        // We disable leak detection in the child since the inherited memory will
        // be reclaimed when the child process exits.
        __lsan_disable();
#endif
        dd_internal_handle_fork();
    } else {
        // PARENT PROCESS
        // Restart master listener if it was shut down before fork
        dd_postfork_parent();

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
