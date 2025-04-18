#ifndef _WIN32
#include <stdatomic.h>
#else
#include <components/atomic_win32_polyfill.h>
#endif

#include "limiter.h"
#include "../zend_hrtime.h"

#include <components-rs/sidecar.h>
#include "../sidecar.h"

// clang-format Off

typedef struct {
    /* limit from configuration DD_TRACE_RATE_LIMIT */
    uint32_t limit;
    struct {
        _Atomic(int64_t) hit_count;
        _Atomic(uint64_t) last_update;
        _Atomic(int64_t) recent_total;
    } window;
} ddtrace_limiter;

static ddog_MappedMem_ShmHandle *dd_limiter_mapped_shm;
static ddtrace_limiter* dd_limiter;


void ddtrace_limiter_create() {
    if (zai_config_memoized_entries[DDTRACE_CONFIG_DD_TRACE_SAMPLE_RATE].name_index == ZAI_CONFIG_ORIGIN_DEFAULT) {
        return;
    }

    uint32_t limit = (uint32_t) get_global_DD_TRACE_RATE_LIMIT();

    if (!limit) {
        return;
    }

    // We share the limiter among forks (ie, forks need to write this memory), this requires that we map the memory as shared
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

bool ddtrace_limiter_active() {
    if (!dd_limiter) {
        return false;
    }

    return true;
}

bool ddtrace_limiter_allow() {
    if (!get_global_DD_APM_TRACING_ENABLED()) {
        return true;
    }
    ZEND_ASSERT(dd_limiter);

    uint64_t timeval = zend_hrtime();

    uint64_t old_time = atomic_exchange(&dd_limiter->window.last_update, timeval);
    int64_t clear_counter = (int64_t)((long double)(timeval - old_time) * dd_limiter->limit);

    atomic_fetch_add(&dd_limiter->window.recent_total, ZEND_NANO_IN_SEC - (int64_t)((long double)(timeval - old_time) / ZEND_NANO_IN_SEC * atomic_load(&dd_limiter->window.recent_total)));

    int64_t previous_hits = atomic_fetch_sub(&dd_limiter->window.hit_count, clear_counter);
    if (previous_hits < clear_counter) {
        atomic_fetch_add(&dd_limiter->window.hit_count, previous_hits > 0 ? clear_counter - previous_hits : clear_counter);
    }

    previous_hits = atomic_fetch_add(&dd_limiter->window.hit_count, ZEND_NANO_IN_SEC);
    if ((long double)previous_hits / ZEND_NANO_IN_SEC >= dd_limiter->limit) {
        atomic_fetch_sub(&dd_limiter->window.hit_count, ZEND_NANO_IN_SEC);
        return false; // limit exceeded
    }
    return true;
}

double ddtrace_limiter_rate() {
    int64_t recent_total = atomic_load(&dd_limiter->window.recent_total) / ZEND_NANO_IN_SEC;
    return dd_limiter->limit > recent_total ? 1 : dd_limiter->limit / (double)recent_total;
}

void ddtrace_limiter_destroy() {
    if (dd_limiter_mapped_shm) {
        ddog_drop_anon_shm_handle(ddog_unmap_shm(dd_limiter_mapped_shm));
        dd_limiter = NULL;
    }
}

// clang-format on
