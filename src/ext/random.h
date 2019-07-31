#ifndef DD_RANDOM_H
#define DD_RANDOM_H
#include <Zend/zend_types.h>
#include <php.h>
#include <stdint.h>

#define DD_TRACE_DEBUG_PRNG_SEED "DD_TRACE_DEBUG_PRNG_SEED"

// We keep a separate stack for span ID generation since spans are
// generated from userland as well
typedef struct _ddtrace_span_ids_t {
    uint64_t id;
    struct _ddtrace_span_ids_t *next;
} ddtrace_span_ids_t;

void dd_trace_seed_prng(TSRMLS_D);
void dd_trace_init_span_id_stack(TSRMLS_D);
void dd_trace_free_span_id_stack(TSRMLS_D);
uint64_t dd_trace_push_span_id(TSRMLS_D);
uint64_t dd_trace_pop_span_id(TSRMLS_D);
uint64_t dd_trace_peek_span_id(TSRMLS_D);

#endif  // DD_RANDOM_H
