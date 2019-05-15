#ifndef DD_SHARED_STORAGE_H
#define DD_SHARED_STORAGE_H

#include <stdint.h>
#include "version.h"
#include <stdatomic.h>


typedef struct dd_trace_circuit_breaker_t {
    _Atomic uint32_t consecutive_failures;
    _Atomic uint32_t total_failures;
    _Atomic uint32_t flags;
    _Atomic uint64_t circuit_opened_timestamp;
} dd_trace_circuit_breaker_t;


#define DD_TRACE_CB_OPENED (1 << 0)
#define DD_TRACE_SHMEM_KEY ("/dd_trace_shmem_" PHP_DDTRACE_VERSION)

void dd_tracer_circuit_breaker_register_error_code(uint32_t error_code);
void dd_tracer_circuit_breaker_register_timeout(uint32_t timeout);
void dd_tracer_circuit_breaker_register_success();
uint32_t dd_tracer_circuit_breaker_is_closed();


#endif //DD_SHARED_STORAGE_H
