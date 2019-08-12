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

void ddtrace_seed_prng(TSRMLS_D);
void ddtrace_init_span_id_stack(TSRMLS_D);
void ddtrace_free_span_id_stack(TSRMLS_D);
uint64_t ddtrace_push_span_id(TSRMLS_D);
uint64_t ddtrace_pop_span_id(TSRMLS_D);
uint64_t ddtrace_peek_span_id(TSRMLS_D);

#endif  // DD_RANDOM_H
