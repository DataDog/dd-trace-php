#ifndef DD_RANDOM_H
#define DD_RANDOM_H
#include <Zend/zend_types.h>
#include <php.h>

#define DD_TRACE_DEBUG_PRNG_SEED "DD_TRACE_DEBUG_PRNG_SEED"

// We keep a separate stack for span ID generation since spans are
// generated from userland as well
typedef struct _ddtrace_span_ids_t {
    unsigned long long id;
    struct _ddtrace_span_ids_t *next;
} ddtrace_span_ids_t;

long long dd_trace_raw_generate_id(TSRMLS_D);
long long dd_trace_pop_span_id(TSRMLS_D);
long long dd_trace_active_span_id(TSRMLS_D);
void dd_trace_seed_prng(TSRMLS_D);
#if PHP_VERSION_ID >= 70200
zend_string *dd_trace_generate_id(TSRMLS_D);
#else
void dd_trace_generate_id(char* buf TSRMLS_DC);
#endif

#endif  // DD_RANDOM_H
