#include "threads.h"
#include <Zend/zend.h>
#include "ddtrace.h"

#if ZTS

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

    // avoid deadlocks due to signal handlers accessing this
    HANDLE_BLOCK_INTERRUPTIONS();
    tsrm_mutex_lock(ddtrace_threads_mutex);

    zend_hash_index_add_new_ptr(&ddtrace_tls_bases, (zend_ulong)(uintptr_t)tsrm_thread_id(), TSRMLS_CACHE);

    tsrm_mutex_unlock(ddtrace_threads_mutex);
    HANDLE_UNBLOCK_INTERRUPTIONS();
}

void ddtrace_thread_gshutdown() {
    if (ddtrace_threads_mutex) {
        // avoid deadlocks due to signal handlers accessing this
        HANDLE_BLOCK_INTERRUPTIONS();
        tsrm_mutex_lock(ddtrace_threads_mutex);

        zend_hash_index_del(&ddtrace_tls_bases, (zend_ulong)(uintptr_t)tsrm_thread_id());

        tsrm_mutex_unlock(ddtrace_threads_mutex);
        HANDLE_UNBLOCK_INTERRUPTIONS();
    }
}

#endif
