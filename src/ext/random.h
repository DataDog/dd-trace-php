#ifndef DD_RANDOM_H
#define DD_RANDOM_H
#include <Zend/zend_types.h>

#define DD_TRACE_DEBUG_PRNG_SEED "DD_TRACE_DEBUG_PRNG_SEED"

void dd_trace_seed_prng();
zend_string *dd_trace_generate_id();

#endif  // DD_RANDOM_H
