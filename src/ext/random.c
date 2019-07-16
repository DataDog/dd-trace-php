#include "random.h"

#include <php.h>

#include <ext/standard/php_rand.h>

#include "configuration.h"
#include "third-party/mt19937-64.h"

void dd_trace_seed_prng(TSRMLS_D) {
    if (get_dd_trace_debug_prng_seed() > 0) {
        init_genrand64((unsigned long long)get_dd_trace_debug_prng_seed());
    } else {
        init_genrand64((unsigned long long)GENERATE_SEED());
    }
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
