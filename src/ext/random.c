#include "random.h"

#include <php.h>
#include <stdlib.h>

#include <ext/standard/php_rand.h>

#include "configuration.h"
#include "ddtrace.h"
#include "env_config.h"
#include "third-party/mt19937-64.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void ddtrace_seed_prng(TSRMLS_D) {
    if (get_dd_trace_debug_prng_seed() > 0) {
        init_genrand64((unsigned long long)get_dd_trace_debug_prng_seed());
    } else {
        init_genrand64((unsigned long long)GENERATE_SEED());
    }
}

void ddtrace_init_span_id_stack(TSRMLS_D) {
    DDTRACE_G(trace_id) = 0;
    DDTRACE_G(span_ids_top) = NULL;
}

void ddtrace_free_span_id_stack(TSRMLS_D) {
    DDTRACE_G(trace_id) = 0;
    while (DDTRACE_G(span_ids_top) != NULL) {
        ddtrace_span_ids_t *stack = DDTRACE_G(span_ids_top);
        DDTRACE_G(span_ids_top) = stack->next;
        efree(stack);
    }
}

static inline uint64_t zval_to_uint64(zval *zid) {
    if (!zid || Z_TYPE_P(zid) != IS_STRING) {
        return 0U;
    }
    const char *id = Z_STRVAL_P(zid);
#if PHP_VERSION_ID >= 70000
    size_t i = 0;
#else
    int i = 0;
#endif
    for (; i < Z_STRLEN_P(zid); i++) {
        if (id[i] < '0' || id[i] > '9') {
            return 0U;
        }
    }
    errno = 0;
    uint64_t uid = (uint64_t)strtoull(id, NULL, 10);
    return (uid && errno == 0) ? uid : 0U;
}

BOOL_T ddtrace_set_userland_trace_id(zval *zid TSRMLS_DC) {
    uint64_t uid = zval_to_uint64(zid);
    if (uid) {
        DDTRACE_G(trace_id) = uid;
        return TRUE;
    }
    return FALSE;
}

uint64_t ddtrace_push_span_id(uint64_t id TSRMLS_DC) {
    ddtrace_span_ids_t *stack = ecalloc(1, sizeof(ddtrace_span_ids_t));
    // Shift one bit to get 63-bit; add 1 since "0" can indicate a root span
    stack->id = id ? id : (uint64_t)((genrand64_int64() >> 1) + 1);
    stack->next = DDTRACE_G(span_ids_top);
    DDTRACE_G(span_ids_top) = stack;
    // If a distributed trace has not set this value before an ID is generated,
    // use the first generated ID as the trace_id
    if (DDTRACE_G(trace_id) == 0) {
        DDTRACE_G(trace_id) = stack->id;
    }
    DDTRACE_G(open_spans_count)++;
    return stack->id;
}

BOOL_T ddtrace_push_userland_span_id(zval *zid TSRMLS_DC) {
    uint64_t uid = zval_to_uint64(zid);
    if (uid) {
        ddtrace_push_span_id(uid TSRMLS_CC);
        return TRUE;
    }
    return FALSE;
}

uint64_t ddtrace_pop_span_id(TSRMLS_D) {
    if (DDTRACE_G(span_ids_top) == NULL) {
        return 0;
    }
    uint64_t id;
    ddtrace_span_ids_t *stack = DDTRACE_G(span_ids_top);
    DDTRACE_G(span_ids_top) = stack->next;
    id = stack->id;
    if (DDTRACE_G(span_ids_top) == NULL) {
        DDTRACE_G(trace_id) = 0;
    }
    efree(stack);
    DDTRACE_G(closed_spans_count)++;
    DDTRACE_G(open_spans_count)--;
    return id;
}

uint64_t ddtrace_peek_span_id(TSRMLS_D) {
    if (DDTRACE_G(span_ids_top) == NULL) {
        return 0;
    }
    return DDTRACE_G(span_ids_top)->id;
}
