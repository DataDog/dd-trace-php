#ifndef DD_RANDOM_H
#define DD_RANDOM_H
#include <Zend/zend_types.h>
#include <php.h>

#define DD_TRACE_DEBUG_PRNG_SEED "DD_TRACE_DEBUG_PRNG_SEED"

long long dd_trace_raw_generate_id(TSRMLS_D);
void dd_trace_seed_prng(TSRMLS_D);
#if PHP_VERSION_ID >= 70200
zend_string *dd_trace_generate_id(TSRMLS_D);
#else
void dd_trace_generate_id(char* buf TSRMLS_DC);
#endif

#endif  // DD_RANDOM_H
