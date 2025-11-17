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
static ddtrace_standalone_limiter dd_local_limiter;

void ddtrace_standalone_limiter_create() {
    uint32_t limit = 1;

    ddog_ShmHandle *shm;
    size_t _size;
    if (ddtrace_ffi_try("Failed allocating shared memory", ddog_alloc_anon_shm_handle(limit, &shm))) {
        if (!ddtrace_ffi_try("Failed mapping shared memory", ddog_map_shm(shm, &dd_limiter_mapped_shm, (void **)&dd_limiter, &_size))) {
            dd_limiter = &dd_local_limiter;
            ddog_drop_anon_shm_handle(shm);
        }
    } else {
        dd_limiter = &dd_local_limiter;
    }

    dd_limiter->limit = limit;
    memset(&dd_limiter->window, 0, sizeof(dd_limiter->window));
}

static bool tick() {
    ZEND_ASSERT(dd_limiter);

    uint64_t timeval = zend_hrtime() / 60000000000;
    uint64_t old_time = atomic_exchange(&dd_limiter->window.last_hit, timeval);

    return timeval != old_time;
}

void ddtrace_standalone_limiter_hit() { tick(); }

bool ddtrace_standalone_limiter_allow() { return tick(); }

void ddtrace_standalone_limiter_destroy() {
    if (dd_limiter_mapped_shm) {
        ddog_drop_anon_shm_handle(ddog_unmap_shm(dd_limiter_mapped_shm));
        dd_limiter = NULL;
    }
}