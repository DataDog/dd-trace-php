#include "standalone_limiter.h"

#ifndef _WIN32
#include <stdatomic.h>
#else
#include <components/atomic_win32_polyfill.h>
#endif

#include <components-rs/sidecar.h>

#include "ddtrace.h"
#include "sidecar.h"
#include "zend_hrtime.h"

typedef struct {
    /* limit from configuration DD_TRACE_RATE_LIMIT */
    uint32_t limit;
    struct {
        _Atomic(uint64_t) last_hit;
    } window;
} ddtrace_standalone_limiter;

static ddog_MappedMem_ShmHandle *dd_limiter_mapped_shm;
static ddtrace_standalone_limiter *dd_limiter;

void ddtrace_standalone_limiter_create() {
    uint32_t limit = (uint32_t) 1;

    ddog_ShmHandle *shm;
    if (!ddtrace_ffi_try("Failed allocating shared memory", ddog_alloc_anon_shm_handle(limit, &shm))) {
        return;
    }
    size_t _size;
    if (!ddtrace_ffi_try("Failed mapping shared memory", ddog_map_shm(shm, &dd_limiter_mapped_shm, (void **)&dd_limiter, &_size))) {
        ddog_drop_anon_shm_handle(shm);
        return;
    }

    dd_limiter->limit = limit;
    memset(&dd_limiter->window, 0, sizeof(dd_limiter->window));
}

void ddtrace_standalone_limiter_hit() {
    ZEND_ASSERT(dd_limiter);

    uint64_t timeval = zend_hrtime();

    atomic_store(&dd_limiter->window.last_hit, timeval);
}

bool ddtrace_standalone_limiter_allow() {
    ZEND_ASSERT(dd_limiter);

    uint64_t timeval = zend_hrtime();
    uint64_t old_time = atomic_load(&dd_limiter->window.last_hit);

    if (old_time - timeval < 60000000000) {
        return false;
    }

    atomic_store(&dd_limiter->window.last_hit, timeval);

    return true;
}

void ddtrace_standalone_limiter_destroy() {
    if (dd_limiter_mapped_shm) {
        ddog_drop_anon_shm_handle(ddog_unmap_shm(dd_limiter_mapped_shm));
        dd_limiter = NULL;
    }
}