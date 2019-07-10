#ifndef DD_CIRCUIT_BREAKER_H
#define DD_CIRCUIT_BREAKER_H

#include <stdint.h>

#include "vendor_stdatomic.h"
#include "version.h"

typedef struct dd_trace_circuit_breaker_t {
    _Atomic(uint32_t) consecutive_failures;
    _Atomic(uint32_t) total_failures;
    _Atomic(uint32_t) flags;
    _Atomic(uint64_t) circuit_opened_timestamp;
    _Atomic(uint64_t) last_failure_timestamp;
} dd_trace_circuit_breaker_t;

#define DD_TRACE_CIRCUIT_BREAKER_OPENED (1 << 0)
#define DD_TRACE_CIRCUIT_BREAKER_SHMEM_KEY ("/dd_trace_shmem_" PHP_DDTRACE_VERSION)
#define DD_TRACE_CIRCUIT_BREAKER_DEFAULT_MAX_CONSECUTIVE_FAILURES 3
#define DD_TRACE_CIRCUIT_BREAKER_DEFAULT_RETRY_TIME_MSEC 5000

#define DD_TRACE_CIRCUIT_BREAKER_ENV_MAX_CONSECUTIVE_FAILURES "DD_TRACE_AGENT_MAX_CONSECUTIVE_FAILURES"
#define DD_TRACE_CIRCUIT_BREAKER_ENV_RETRY_TIME_MSEC "DD_TRACE_AGENT_ATTEMPT_RETRY_TIME_MSEC"

void dd_tracer_circuit_breaker_register_error();
void dd_tracer_circuit_breaker_register_success();
void dd_tracer_circuit_breaker_open();
void dd_tracer_circuit_breaker_close();
uint32_t dd_tracer_circuit_breaker_can_try();
uint32_t dd_tracer_circuit_breaker_is_closed();
uint32_t dd_tracer_circuit_breaker_total_failures();
uint32_t dd_tracer_circuit_breaker_consecutive_failures();
uint64_t dd_tracer_circuit_breaker_opened_timestamp();
uint64_t dd_tracer_circuit_breaker_last_failure_timestamp();

#endif  // DD_CIRCUIT_BREAKER_H
