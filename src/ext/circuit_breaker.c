#include "circuit_breaker.h"

#include <stdlib.h>
#include <unistd.h>
#include <stdio.h>
#include <sys/stat.h>
#include <sys/mman.h>
#include <sys/types.h>
#include <time.h>
#include <fcntl.h>

dd_trace_circuit_breaker_t *dd_trace_circuit_breaker = NULL;
dd_trace_circuit_breaker_t local_dd_trace_circuit_breaker = {
    .consecutive_failures = {0},
    .total_failures = {0},
    .flags = {0},
    .circuit_opened_timestamp = {0}
};

static void handle_perpare_error(const char *call_name) {
    perror(call_name);

    if (!dd_trace_circuit_breaker) {
        // if shared memory is not working use local copy
        dd_trace_circuit_breaker = &local_dd_trace_circuit_breaker;
    }
}

static dd_trace_circuit_breaker_t *prepare_cb() {
    if (!dd_trace_circuit_breaker) {
        int shm_fd = shm_open(DD_TRACE_CIRCUIT_BREAKER_SHMEM_KEY, O_CREAT | O_RDWR, 0666);
        if (shm_fd < 0) {
            handle_perpare_error("shm_open");
            return;
        }

        // initialize size if not yet initialized
        // fetch stat with shmem size
        struct stat stats;
        if (fstat( shm_fd, &stats) != 0){
            handle_perpare_error("fstat");
            return;
        }
        // do the resize
        if (stats.st_size != sizeof(dd_trace_circuit_breaker_t)) {
            if (ftruncate(shm_fd, sizeof(dd_trace_circuit_breaker_t)) != 0) {
                handle_perpare_error("ftruncate");
                return;
            }
        }
        // mmap shared memory to local memory
        dd_trace_circuit_breaker_t *shared_breaker = mmap(NULL, sizeof(dd_trace_circuit_breaker_t), PROT_READ | PROT_WRITE, MAP_SHARED, shm_fd, 0);
        if (shared_breaker == MAP_FAILED) {
            handle_perpare_error("mmap");

            return;
        }

        dd_trace_circuit_breaker = shared_breaker;
    }
}

static uint64_t current_timestamp_monotonic_ms() {
    struct timespec t;
    clock_gettime(CLOCK_MONOTONIC, &t);

    return t.tv_sec * 1000 * 1000 + t.tv_nsec / 1000;
}


uint32_t dd_tracer_circuit_breaker_can_retry(){
    uint64_t opened_timestamp = atomic_load(&dd_trace_circuit_breaker->circuit_opened_timestamp);
    uint64_t current_time = current_timestamp_monotonic_ms();
    if ((opened_timestamp) < current_time) {

    }
}

void dd_tracer_circuit_breaker_register_error(){
    prepare_cb();

    atomic_fetch_add(&dd_trace_circuit_breaker->consecutive_failures, 1);
    atomic_fetch_add(&dd_trace_circuit_breaker->total_failures, 1);

    if (atomic_load(&dd_trace_circuit_breaker->consecutive_failures) > DD_TRACE_CIRCUIT_BREAKER_MAX_CONSECUTIVE_FAILURES) {
        dd_tracer_circuit_breaker_close();
    }
}

void dd_tracer_circuit_breaker_register_success() {
    prepare_cb();

    atomic_store(&dd_trace_circuit_breaker->consecutive_failures, 0);
}

void dd_tracer_circuit_breaker_open() {
    prepare_cb();

    atomic_fetch_or(&dd_trace_circuit_breaker->flags, DD_TRACE_CIRCUIT_BREAKER_OPENED);
    atomic_store(&dd_trace_circuit_breaker->consecutive_failures, 0);
}

void dd_tracer_circuit_breaker_close() {
    prepare_cb();

    atomic_fetch_and(&dd_trace_circuit_breaker->flags, (uint32_t)~DD_TRACE_CIRCUIT_BREAKER_OPENED);
}

uint32_t dd_tracer_circuit_breaker_is_closed() {
    prepare_cb();

    return (atomic_load(&dd_trace_circuit_breaker->flags) ^ DD_TRACE_CIRCUIT_BREAKER_OPENED) != 0;
}
