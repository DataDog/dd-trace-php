#include "threads.h"
#define zend_signal_globals_id zend_signal_globals_id_dummy
#define zend_signal_globals_offset zend_signal_globals_offset_dummy
#include <Zend/zend.h>
#undef zend_signal_globals_id
#undef zend_signal_globals_offset
#include "ddtrace.h"

#if ZTS

#ifdef ZEND_SIGNALS
#ifdef __APPLE__
extern __attribute__((weak, weak_import)) int zend_signal_globals_id;
extern __attribute__((weak, weak_import)) size_t zend_signal_globals_offset;
#elif defined(_WIN32)
#error "Found zend_signals under windows!?"
#else
__attribute__((weak)) int zend_signal_globals_id;
__attribute__((weak)) size_t zend_signal_globals_offset;
#endif
#endif

HashTable ddtrace_tls_bases; // map thread id to TSRMLS_CACHE
MUTEX_T ddtrace_threads_mutex = NULL;

void ddtrace_thread_mshutdown() {
    if (ddtrace_threads_mutex) {
        tsrm_mutex_free(ddtrace_threads_mutex);
        ddtrace_threads_mutex = NULL;
        zend_hash_destroy(&ddtrace_tls_bases);
    }
}

void ddtrace_thread_ginit() {
    if (!ddtrace_threads_mutex) {
        ddtrace_threads_mutex = tsrm_mutex_alloc();
        zend_hash_init(&ddtrace_tls_bases, 8, NULL, NULL, 1);
    }

#ifdef ZEND_SIGNALS
    // avoid deadlocks due to signal handlers accessing this
    if (zend_signal_globals_id) {
        HANDLE_BLOCK_INTERRUPTIONS();
    }
#endif
    tsrm_mutex_lock(ddtrace_threads_mutex);

    zend_hash_index_add_new_ptr(&ddtrace_tls_bases, (zend_ulong)(uintptr_t)tsrm_thread_id(), TSRMLS_CACHE);

    tsrm_mutex_unlock(ddtrace_threads_mutex);
#ifdef ZEND_SIGNALS
    if (zend_signal_globals_id) {
        HANDLE_UNBLOCK_INTERRUPTIONS();
    }
#endif
}

void ddtrace_thread_gshutdown() {
    if (ddtrace_threads_mutex) {
#ifdef ZEND_SIGNALS
        // avoid deadlocks due to signal handlers accessing this
        if (zend_signal_globals_id) {
            HANDLE_BLOCK_INTERRUPTIONS();
        }
#endif
        tsrm_mutex_lock(ddtrace_threads_mutex);

        zend_hash_index_del(&ddtrace_tls_bases, (zend_ulong)(uintptr_t)tsrm_thread_id());

        tsrm_mutex_unlock(ddtrace_threads_mutex);
#ifdef ZEND_SIGNALS
        if (zend_signal_globals_id) {
            HANDLE_UNBLOCK_INTERRUPTIONS();
        }
#endif
    }
}

#endif
