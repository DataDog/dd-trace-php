#include "circuit_breaker.h"

#include <fcntl.h>
#include <stdio.h>
#include <stdlib.h>
#ifndef _WIN32
#include <sys/mman.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <unistd.h>
#else
#define _AMD64_ // Avoid target architecture issues
#include <tchar.h>
#include <minwindef.h>
#include <minwinbase.h>
#endif
#include "zend_hrtime.h"

#include "configuration.h"
#include "macros.h"
#include "random.h"

dd_trace_circuit_breaker_t *dd_trace_circuit_breaker = NULL;
dd_trace_circuit_breaker_t local_dd_trace_circuit_breaker = {0};

static void handle_prepare_error(const char *call_name) {
    perror(call_name);

    if (!dd_trace_circuit_breaker) {
        // if shared memory is not working use local copy
        dd_trace_circuit_breaker = &local_dd_trace_circuit_breaker;
    }
}

#ifdef _WIN32
TCHAR dd_shmem_name[] = TEXT("Local\\" DD_TRACE_CIRCUIT_BREAKER_SHMEM_KEY);
#endif

static void prepare_cb() {
    if (!dd_trace_circuit_breaker) {
#ifndef _WIN32
        int shm_fd = shm_open(DD_TRACE_CIRCUIT_BREAKER_SHMEM_KEY, O_CREAT | O_RDWR, 0666);
        if (shm_fd < 0) {
            handle_prepare_error("shm_open");
            return;
        }

        // initialize size if not yet initialized
        // fetch stat with shmem size
        struct stat stats;
        if (fstat(shm_fd, &stats) != 0) {
            handle_prepare_error("fstat");
            return;
        }
        // do the resize if size is too small
        if (stats.st_size < 0 || (size_t)stats.st_size < sizeof(dd_trace_circuit_breaker_t)) {
            if (ftruncate(shm_fd, sizeof(dd_trace_circuit_breaker_t)) != 0) {
                handle_prepare_error("ftruncate");
                return;
            }
        }
        // mmap shared memory to local memory
        dd_trace_circuit_breaker_t *shared_breaker =
            mmap(NULL, sizeof(dd_trace_circuit_breaker_t), PROT_READ | PROT_WRITE, MAP_SHARED, shm_fd, 0);
        if (shared_breaker == MAP_FAILED) {
            handle_prepare_error("mmap");

            return;
        }
#else
        HANDLE file_mapping = CreateFileMapping(INVALID_HANDLE_VALUE, NULL, PAGE_READWRITE, 0, sizeof(dd_trace_circuit_breaker_t), dd_shmem_name);
        if (!file_mapping) {
            handle_prepare_error("CreateFileMapping");
            return;
        }
        dd_trace_circuit_breaker_t *shared_breaker = MapViewOfFile(file_mapping, FILE_MAP_ALL_ACCESS, 0, 0, sizeof(dd_trace_circuit_breaker_t));
        if (!shared_breaker) {
            handle_prepare_error("MapViewOfFile");
            CloseHandle(file_mapping);
            return;
        }
#endif

        dd_trace_circuit_breaker = shared_breaker;
    }
}

static uint64_t current_timestamp_monotonic_usec() {
    return zend_hrtime() / 1000;
}

uint32_t dd_tracer_circuit_breaker_can_try(void) {
    if (dd_tracer_circuit_breaker_is_closed()) {
        return 1;
    }
    uint64_t last_failure_timestamp = atomic_load(&dd_trace_circuit_breaker->last_failure_timestamp);
    uint64_t current_time = current_timestamp_monotonic_usec();

    return (last_failure_timestamp + get_DD_TRACE_AGENT_ATTEMPT_RETRY_TIME_MSEC() * 1000) <= current_time;
}

void dd_tracer_circuit_breaker_register_error(void) {
    prepare_cb();

    atomic_fetch_add(&dd_trace_circuit_breaker->consecutive_failures, 1);
    atomic_fetch_add(&dd_trace_circuit_breaker->total_failures, 1);

    atomic_store(&dd_trace_circuit_breaker->last_failure_timestamp, current_timestamp_monotonic_usec());

    // if circuit breaker is closed attempt to open it if consecutive failures are higher than the threshold
    if (dd_tracer_circuit_breaker_is_closed()) {
        if ((int)atomic_load(&dd_trace_circuit_breaker->consecutive_failures) >=
            get_DD_TRACE_AGENT_MAX_CONSECUTIVE_FAILURES()) {
            dd_tracer_circuit_breaker_open();
        }
    }
}

void dd_tracer_circuit_breaker_register_success() {
    prepare_cb();

    atomic_store(&dd_trace_circuit_breaker->consecutive_failures, 0);
    dd_tracer_circuit_breaker_close();
}

void dd_tracer_circuit_breaker_open() {
    prepare_cb();

    atomic_fetch_or(&dd_trace_circuit_breaker->flags, DD_TRACE_CIRCUIT_BREAKER_OPENED);
    atomic_store(&dd_trace_circuit_breaker->circuit_opened_timestamp, current_timestamp_monotonic_usec());
}

void dd_tracer_circuit_breaker_close() {
    prepare_cb();

    atomic_fetch_and(&dd_trace_circuit_breaker->flags, (uint32_t)~DD_TRACE_CIRCUIT_BREAKER_OPENED);
}

uint32_t dd_tracer_circuit_breaker_is_closed() {
    prepare_cb();

    return (atomic_load(&dd_trace_circuit_breaker->flags) ^ DD_TRACE_CIRCUIT_BREAKER_OPENED) != 0;
}

uint32_t dd_tracer_circuit_breaker_total_failures() {
    prepare_cb();

    return atomic_load(&dd_trace_circuit_breaker->total_failures);
}

uint32_t dd_tracer_circuit_breaker_consecutive_failures() {
    prepare_cb();

    return atomic_load(&dd_trace_circuit_breaker->consecutive_failures);
}

uint64_t dd_tracer_circuit_breaker_opened_timestamp() {
    prepare_cb();

    return atomic_load(&dd_trace_circuit_breaker->circuit_opened_timestamp);
}

uint64_t dd_tracer_circuit_breaker_last_failure_timestamp() {
    prepare_cb();

    return atomic_load(&dd_trace_circuit_breaker->last_failure_timestamp);
}
