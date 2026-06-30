#include "remote_config.h"
#include "datadog.h"
#include "ffi_utils.h"
#include "sidecar.h"
#include <zai_string/string.h>
#include <components/log/log.h>
#include "threads.h"
#include <tracer/tracer_api.h>
#ifndef _WIN32
#include <sys/time.h>
#include <tracer/ddtrace_globals.h>
#endif

#if PHP_VERSION_ID < 70100
#include <interceptor/php7/interceptor.h>
#define zend_interrupt_function zai_interrupt_function
#define zend_vm_interrupt zai_vm_interrupt
#endif

ZEND_EXTERN_MODULE_GLOBALS(datadog);

static void (*dd_prev_interrupt_function)(zend_execute_data *execute_data);
static void dd_vm_interrupt(zend_execute_data *execute_data) {
    if (dd_prev_interrupt_function) {
        dd_prev_interrupt_function(execute_data);
    }
    if (DATADOG_G(remote_config_state) && DATADOG_G(reread_remote_configuration)) {
        LOG(INFO, "Rereading remote configurations after interrupt");
        DATADOG_G(reread_remote_configuration) = 0;
        ddog_process_remote_configs(DATADOG_G(remote_config_state));
    }
}

// We need this exported to call it via CreateRemoteThread on Windows
DATADOG_PUBLIC void datadog_set_all_thread_vm_interrupt(void) {
    // broadcast interrupt to all threads on ZTS
#if ZTS
    tsrm_mutex_lock(datadog_threads_mutex);

    void *TSRMLS_CACHE; // EG() accesses a variable named TSRMLS_CACHE. Make use of variable shadowing in scopes...
    ZEND_HASH_FOREACH_PTR(&datadog_tls_bases, TSRMLS_CACHE) {
#endif
#if PHP_VERSION_ID >= 80200
        zend_atomic_bool_store_ex(&EG(vm_interrupt), 1);
#elif PHP_VERSION_ID >= 70100
        EG(vm_interrupt) = 1;
#else
        DATADOG_G(zai_vm_interrupt) = 1;
#endif
        DATADOG_G(reread_remote_configuration) = 1;
#if ZTS
    } ZEND_HASH_FOREACH_END();

    tsrm_mutex_unlock(datadog_threads_mutex);
#endif
}

void datadog_check_for_new_config_now(void) {
    if (DATADOG_G(remote_config_state) && !DATADOG_G(reread_remote_configuration) && ddog_process_remote_configs(DATADOG_G(remote_config_state))) {
        // If we blocked the signal, notify the other threads too
        datadog_set_all_thread_vm_interrupt();
    }
}

#ifndef _WIN32
static void dd_sigvtalarm_handler(int signal, siginfo_t *siginfo, void *ctx) {
    UNUSED(signal, siginfo, ctx);
    datadog_set_all_thread_vm_interrupt();

#if defined(__linux__) && defined(ZTS)
    if (!tsrm_is_managed_thread()) {
        return;
    }
#endif

    uint64_t now_ns = 0;
#if !defined(__linux__) && defined(ZTS)
    // On macOS ZTS, setitimer is per-process; the signal may land on any thread - iterate all threads to check for expirations
    uint64_t next_deadline = ~0ull;
    tsrm_mutex_lock(datadog_threads_mutex);
    void *TSRMLS_CACHE;
    ZEND_HASH_FOREACH_PTR(&datadog_tls_bases, TSRMLS_CACHE) {
#endif
    // On Linux the signal gets delivered to the thread that set the timer, so we don't need to iterate all threads
    uint64_t deadline = DDTRACE_G(capture_deadline_ns);
    if (deadline) {
        if (!now_ns) {
            struct timespec now;
            clock_gettime(CLOCK_THREAD_CPUTIME_ID, &now);
            now_ns = (uint64_t)now.tv_sec * 1000000000ULL + (uint64_t)now.tv_nsec;
        }
        if (now_ns >= deadline) {
            DDTRACE_G(debugger_capture_timed_out) = 1;
        }
#if !defined(__linux__) && defined(ZTS)
        else {
            next_deadline = MIN(deadline, next_deadline);
        }
#endif
    }
#if !defined(__linux__) && defined(ZTS)
    } ZEND_HASH_FOREACH_END();
    if (next_deadline != ~0ull) { // re-arm the timer, for ZTS concurrency
        uint64_t usec = (next_deadline - now_ns) / 1000ull;
        struct itimerval it = {
            .it_value    = { .tv_sec = usec / 1000000, .tv_usec = usec % 1000000 },
            .it_interval = { 0, 0 },
        };
        setitimer(ITIMER_VIRTUAL, &it, NULL);
    }
    tsrm_mutex_unlock(datadog_threads_mutex);
#endif
}
#endif

static zend_string *dd_dynamic_configuration_update(ddog_CharSlice config, zend_string *value, ddog_DynamicConfigUpdateMode mode) {
    zend_string *name = dd_CharSlice_to_zend_string(config);
    zend_string *ret = NULL;
    if (mode == DDOG_DYNAMIC_CONFIG_UPDATE_MODE_RESTORE) {
        zend_restore_ini_entry(name, PHP_INI_STAGE_RUNTIME);
    } else if (mode == DDOG_DYNAMIC_CONFIG_UPDATE_MODE_READ_WRITE) {
        zend_ini_entry *ini_entry = zend_hash_find_ptr(EG(ini_directives), name);
        zend_string *old = ini_entry->modified && ini_entry->value ? zend_string_copy(ini_entry->value) : NULL;
        if (zend_alter_ini_entry(name, value, ZEND_INI_USER, ZEND_INI_STAGE_RUNTIME) == SUCCESS) {
            if (old) {
                ret = old;
            } else {
                ret = ddog_DYANMIC_CONFIG_UPDATE_UNMODIFIED;
            }
        }
        zend_string_release(value);
        if (old && ret != old) {
            zend_string_release(old);
        }
    } else if (mode == DDOG_DYNAMIC_CONFIG_UPDATE_MODE_READ) {
        zend_ini_entry *ini_entry = zend_hash_find_ptr(EG(ini_directives), name);
        if (ini_entry->modified && ini_entry->value) {
            ret = ini_entry->value;
        } else {
            ret = ddog_DYANMIC_CONFIG_UPDATE_UNMODIFIED;
        }
    } else {
        ZEND_ASSERT(mode == DDOG_DYNAMIC_CONFIG_UPDATE_MODE_WRITE);
        DATADOG_G(remote_config_writing) = true;
        zend_alter_ini_entry(name, value, ZEND_INI_USER, ZEND_INI_STAGE_RUNTIME);
        DATADOG_G(remote_config_writing) = false;
        zend_string_release(value);
    }
    zend_string_release(name);
    return ret;
}

void datadog_minit_remote_config(void) {
    ddog_setup_remote_config(dd_dynamic_configuration_update, &ddtrace_live_debugger_setup);
    dd_prev_interrupt_function = zend_interrupt_function;
    zend_interrupt_function = dd_vm_interrupt;

#ifndef _WIN32
    struct sigaction act = {0};
    act.sa_flags = SA_SIGINFO | SA_RESTART;
    act.sa_sigaction = dd_sigvtalarm_handler;
    sigaction(SIGVTALRM, &act, NULL);
#endif
}

void datadog_mshutdown_remote_config(void) {
#ifndef _WIN32
    struct sigaction act = {0};
    act.sa_handler = SIG_IGN;
    sigaction(SIGVTALRM, &act, NULL);
#endif
}

void datadog_rinit_remote_config(void) {
    DATADOG_G(reread_remote_configuration) = 0;
}

void datadog_rshutdown_remote_config(void) {
    ddog_rshutdown_remote_config(DATADOG_G(remote_config_state));
}
