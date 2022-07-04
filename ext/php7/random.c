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
    for (size_t i = 0; i < Z_STRLEN_P(zid); i++) {
        if (id[i] < '0' || id[i] > '9') {
            return 0U;
        }
    }
    errno = 0;
    uint64_t uid = (uint64_t)strtoull(id, NULL, 10);
    return (uid && errno == 0) ? uid : 0U;
}

uint64_t ddtrace_parse_hex_span_id_str(const char *id, size_t len) {
    if (len == 0) {
        return 0U;
    }

    for (size_t i = 0; i < len; i++) {
        if ((id[i] < '0' || id[i] > '9') && (id[i] < 'a' || id[i] > 'f')) {
            return 0U;
        }
    }
    errno = 0;
    uint64_t uid = (uint64_t)strtoull(id + MAX(0, (ssize_t)len - 16), NULL, 16);
    return (uid && errno == 0) ? uid : 0U;
}

uint64_t ddtrace_parse_hex_span_id(zval *zid) {
    if (!zid || Z_TYPE_P(zid) != IS_STRING) {
        return 0U;
    }
    return ddtrace_parse_hex_span_id_str(Z_STRVAL_P(zid), Z_STRLEN_P(zid));
}

uint64_t ddtrace_generate_span_id(void) { return (uint64_t)genrand64_int64(); }

uint64_t ddtrace_peek_span_id(void) {
    return DDTRACE_G(open_spans_top) ? DDTRACE_G(open_spans_top)->span.span_id : DDTRACE_G(distributed_parent_trace_id);
}

uint64_t ddtrace_peek_trace_id(void) {
    return DDTRACE_G(open_spans_top) ? DDTRACE_G(open_spans_top)->span.trace_id : DDTRACE_G(trace_id);
}
