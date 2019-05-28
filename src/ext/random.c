#include <php.h>
#include <ext/standard/php_rand.h>

#include "env_config.h"
#include "random.h"
#include "third-party/mt19937-64.h"

void dd_trace_seed_prng() {
    unsigned long long seed = (unsigned long long)ddtrace_get_int_config(DD_TRACE_DEBUG_PRNG_SEED, GENERATE_SEED());
    init_genrand64((unsigned long long) seed);
}

zend_string* dd_trace_generate_id() {
    // We shift one bit to get 63-bit
    long long id = (long long)(genrand64_int64() >> 1);
    return zend_strpprintf(0, "%llu", id);
}
