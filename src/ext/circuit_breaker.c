#include "circuit_breaker.h"

#include <fcntl.h>
#include <stdio.h>
#include <stdlib.h>
#include <sys/mman.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <time.h>
#include <unistd.h>

#include "env_config.h"
#include "macros.h"

dd_trace_circuit_breaker_t *dd_trace_circuit_breaker = NULL;

#ifndef __clang__
// disable checks since older GCC is throwing false errors
#pragma GCC diagnostic push
#pragma GCC diagnostic ignored "-Wmissing-field-initializers"
dd_trace_circuit_breaker_t local_dd_trace_circuit_breaker = {{0}};
#pragma GCC diagnostic pop
#else  //__clang__
dd_trace_circuit_breaker_t local_dd_trace_circuit_breaker = {0};
#endif

static void handle_perpare_error(const char *call_name) {
    perror(call_name);

    if (!dd_trace_circuit_breaker) {
        // if shared memory is not working use local copy
        dd_trace_circuit_breaker = &local_dd_trace_circuit_breaker;
    }
}

static void prepare_cb() {
    if (!dd_trace_circuit_breaker) {
        int shm_fd = shm_open(DD_TRACE_CIRCUIT_BREAKER_SHMEM_KEY, O_CREAT | O_RDWR, 0666);
        if (shm_fd < 0) {
            handle_perpare_error("shm_open");
            return;
        }

        // initialize size if not yet initialized
        // fetch stat with shmem size
        struct stat stats;
        if (fstat(shm_fd, &stats) != 0) {
            handle_perpare_error("fstat");
            return;
        }
        // do the resize if size is too small
        if (stats.st_size < 0 || (size_t)stats.st_size < sizeof(dd_trace_circuit_breaker_t)) {
            if (ftruncate(shm_fd, sizeof(dd_trace_circuit_breaker_t)) != 0) {
                handle_perpare_error("ftruncate");
                return;
            }
        }
        // mmap shared memory to local memory
        dd_trace_circuit_breaker_t *shared_breaker =
            mmap(NULL, sizeof(dd_trace_circuit_breaker_t), PROT_READ | PROT_WRITE, MAP_SHARED, shm_fd, 0);
        if (shared_breaker == MAP_FAILED) {
            handle_perpare_error("mmap");

            return;
        }

        dd_trace_circuit_breaker = shared_breaker;
    }
}

static uint64_t current_timestamp_monotonic_usec() {
    struct timespec t;
    clock_gettime(CLOCK_MONOTONIC, &t);

    return t.tv_sec * 1000 * 1000 + t.tv_nsec / 1000;
}

static int64_t get_max_consecutive_failures(TSRMLS_D) {
    return ddtrace_get_int_config(DD_TRACE_CIRCUIT_BREAKER_ENV_MAX_CONSECUTIVE_FAILURES,
                                  DD_TRACE_CIRCUIT_BREAKER_DEFAULT_MAX_CONSECUTIVE_FAILURES TSRMLS_CC);
}

static int64_t get_retry_time_usec(TSRMLS_D) {
    return ddtrace_get_int_config(DD_TRACE_CIRCUIT_BREAKER_ENV_RETRY_TIME_MSEC,
                                  DD_TRACE_CIRCUIT_BREAKER_DEFAULT_RETRY_TIME_MSEC TSRMLS_CC) *
           1000;
}

uint32_t dd_tracer_circuit_breaker_can_try(TSRMLS_D) {
    if (dd_tracer_circuit_breaker_is_closed()) {
        return 1;
    }
    uint64_t last_failure_timestamp = atomic_load(&dd_trace_circuit_breaker->last_failure_timestamp);
    uint64_t current_time = current_timestamp_monotonic_usec();

    return (last_failure_timestamp + get_retry_time_usec(TSRMLS_C)) <= current_time;
}

void dd_tracer_circuit_breaker_register_error(TSRMLS_D) {
    prepare_cb();

    atomic_fetch_add(&dd_trace_circuit_breaker->consecutive_failures, 1);
    atomic_fetch_add(&dd_trace_circuit_breaker->total_failures, 1);

    atomic_store(&dd_trace_circuit_breaker->last_failure_timestamp, current_timestamp_monotonic_usec());

    // if circuit breaker is closed attempt to open it if consecutive failures are higher thatn the threshold
    if (dd_tracer_circuit_breaker_is_closed()) {
        if (atomic_load(&dd_trace_circuit_breaker->consecutive_failures) >= get_max_consecutive_failures(TSRMLS_C)) {
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
