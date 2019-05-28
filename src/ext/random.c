#include <php.h>
#include <ext/standard/php_rand.h>

#include "env_config.h"
#include "random.h"
#include "third-party/mt19937-64.h"

void dd_trace_seed_prng() {
    unsigned long long seed = (unsigned long long)ddtrace_get_int_config(DD_TRACE_DEBUG_PRNG_SEED, GENERATE_SEED());
    init_genrand64((unsigned long long)seed);
}

static long long generate_id() {
    // We shift one bit to get 63-bit
    return (long long)(genrand64_int64() >> 1);
}

#if PHP_VERSION_ID >= 70200
// zend_strpprintf() wasn't exposed until PHP 7.2
zend_string *dd_trace_generate_id() { return zend_strpprintf(0, "%llu", generate_id()); }
#else
void dd_trace_generate_id(char* buf) { php_sprintf(buf, "%llu", generate_id()); }
#endif
