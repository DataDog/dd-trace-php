#include "random.h"

#include <php.h>
#include <stdlib.h>

#include <ext/standard/php_rand.h>

#include "configuration.h"
#include "ddtrace.h"
#include "mt19937/mt19937-64.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void ddtrace_seed_prng(void) {
    if (get_DD_TRACE_DEBUG_PRNG_SEED() > 0) {
        init_genrand64((unsigned long long)get_DD_TRACE_DEBUG_PRNG_SEED());
    } else {
        init_genrand64((unsigned long long)GENERATE_SEED());
    }
}

uint64_t ddtrace_parse_userland_span_id(zval *zid) {
    if (!zid || Z_TYPE_P(zid) != IS_STRING) {
        return 0U;
    }
    const char *id = Z_STRVAL_P(zid);
    size_t i = 0;
    for (; i < Z_STRLEN_P(zid); i++) {
        if (id[i] < '0' || id[i] > '9') {
            return 0U;
        }
    }
    errno = 0;
    uint64_t uid = (uint64_t)strtoull(id, NULL, 10);
    return (uid && errno == 0) ? uid : 0U;
}

uint64_t ddtrace_generate_span_id(void) { return (uint64_t)genrand64_int64(); }

uint64_t ddtrace_peek_span_id(void) {
    return DDTRACE_G(open_spans_top) ? DDTRACE_G(open_spans_top)->span.span_id : DDTRACE_G(distributed_parent_trace_id);
}

uint64_t ddtrace_peek_trace_id(void) {
    return DDTRACE_G(open_spans_top) ? DDTRACE_G(open_spans_top)->span.trace_id : DDTRACE_G(trace_id);
}
