#include "remote_config.h"
#include "ddtrace.h"
#include "sidecar.h"
#include "hook/uhook.h"
#include "span.h"
#include <zai_string/string.h>
#include <components-rs/sidecar.h>
#include <components/log/log.h>
#include <hook/hook.h>
#include "threads.h"
#include "live_debugger.h"
#ifndef _WIN32
#include <signal.h>
#endif

#if PHP_VERSION_ID < 70100
#include <interceptor/php7/interceptor.h>
#define zend_interrupt_function zai_interrupt_function
#define zend_vm_interrupt zai_vm_interrupt
#endif

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static void (*dd_prev_interrupt_function)(zend_execute_data *execute_data);
static void dd_vm_interrupt(zend_execute_data *execute_data) {
    if (dd_prev_interrupt_function) {
        dd_prev_interrupt_function(execute_data);
    }
    if (DDTRACE_G(remote_config_state)) {
        ddog_process_remote_configs(DDTRACE_G(remote_config_state));
    }
}

// We need this exported to call it via CreateRemoteThread on Windows
DDTRACE_PUBLIC void ddtrace_set_all_thread_vm_interrupt(void) {
    // broadcast interrupt to all threads on ZTS
#if ZTS
    tsrm_mutex_lock(ddtrace_threads_mutex);

    void *TSRMLS_CACHE; // EG() accesses a variable named TSRMLS_CACHE. Make use of variable shadowing in scopes...
    ZEND_HASH_FOREACH_PTR(&ddtrace_tls_bases, TSRMLS_CACHE) {
#endif
#if PHP_VERSION_ID >= 80200
        zend_atomic_bool_store_ex(&EG(vm_interrupt), 1);
#elif PHP_VERSION_ID >= 70100
        EG(vm_interrupt) = 1;
#else
        DDTRACE_G(zai_vm_interrupt) = 1;
#endif
#if ZTS
    } ZEND_HASH_FOREACH_END();

    tsrm_mutex_unlock(ddtrace_threads_mutex);
#endif
}

#ifndef _WIN32
static void dd_sigvtalarm_handler(int signal, siginfo_t *siginfo, void *ctx) {
    UNUSED(signal, siginfo, ctx);
    ddtrace_set_all_thread_vm_interrupt();
}
#endif

static struct ddog_Vec_CChar *dd_dynamic_instrumentation_update(ddog_CharSlice config, ddog_CharSlice value, bool return_old) {
    zend_string *name = dd_CharSlice_to_zend_string(config);
    zend_string *old;
    struct ddog_Vec_CChar *ret = NULL;
    if (return_old) {
        old = zend_string_copy(zend_ini_get_value(name));
    }
    if (zend_alter_ini_entry_chars(name, value.ptr, value.len, ZEND_INI_USER, ZEND_INI_STAGE_RUNTIME) == SUCCESS) {
        if (return_old) {
            ret = ddog_CharSlice_to_owned(dd_zend_string_to_CharSlice(old));
        }
    }
    if (return_old) {
        zend_string_release(old);
    }
    zend_string_release(name);
    return ret;
}

void ddtrace_minit_remote_config(void) {
    ddog_setup_remote_config(dd_dynamic_instrumentation_update, &ddtrace_live_debugger_setup);
    dd_prev_interrupt_function = zend_interrupt_function;
    zend_interrupt_function = dd_vm_interrupt;

#ifndef _WIN32
    struct sigaction act = {0};
    act.sa_flags = SA_RESTART;
    act.sa_sigaction = dd_sigvtalarm_handler;
    sigaction(SIGVTALRM, &act, NULL);
#endif
}

void ddtrace_rinit_remote_config(void) {
    zend_hash_init(&DDTRACE_G(active_rc_hooks), 8, NULL, NULL, 0);
    ddog_rinit_remote_config(DDTRACE_G(remote_config_state));
}

void ddtrace_rshutdown_remote_config(void) {
    ddog_rshutdown_remote_config(DDTRACE_G(remote_config_state));
    zend_hash_destroy(&DDTRACE_G(active_rc_hooks));
}
