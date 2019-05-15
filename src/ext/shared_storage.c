#include "shared_storage.h"

#include <stdlib.h>
#include <unistd.h>
#include <stdio.h>
#include <sys/stat.h>
#include <sys/mman.h>
#include <sys/types.h>
#include <fcntl.h>
#include <stdatomic.h>

dd_trace_circuit_breaker_t *dd_trace_circuit_breaker = NULL;
dd_trace_circuit_breaker_t local_dd_trace_circuit_breaker = { 0 };

static void handle_perpare_error(const char *call_name) {
    perror(call_name); //TODO add PHP option

    if (!dd_trace_circuit_breaker) {
        // if shared memory is not working use local copy
        dd_trace_circuit_breaker = &local_dd_trace_circuit_breaker;
    }
}

static void prepare_cb() {
    if (!dd_trace_circuit_breaker) {
        int shm_fd = shm_open(DD_TRACE_SHMEM_KEY, O_CREAT | O_RDWR, 0666);
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
        // do the risze
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
    }
}


void dd_tracer_circuit_breaker_register_error(){
    prepare_cb();

    atomic_fetch_add(&dd_trace_circuit_breaker->consecutive_failures, 1);
    atomic_fetch_add(&dd_trace_circuit_breaker->total_failures, 1);
}

void dd_tracer_circuit_breaker_register_success() {
    prepare_cb();

    atomic_store(&dd_trace_circuit_breaker->consecutive_failures, 0);
}


void dd_tracer_circuit_breaker_trip() {
    prepare_cb();

    atomic_fetch_or(&dd_trace_circuit_breaker->flags, DD_TRACE_CB_OPENED);
}

void dd_tracer_circuit_breaker_close() {
    prepare_cb();

    atomic_fetch_and(&dd_trace_circuit_breaker->flags, (uint32_t)~DD_TRACE_CB_OPENED);
}
uint32_t dd_tracer_circuit_breaker_is_closed() {
    prepare_cb();

    return atomic_load(&dd_trace_circuit_breaker->flags) ^ DD_TRACE_CB_OPENED;
}
