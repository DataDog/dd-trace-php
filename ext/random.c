#include "random.h"

#include <php.h>
#include <stdlib.h>

#include <ext/standard/php_rand.h>
#include <ext/standard/php_random.h>

#include "configuration.h"
#include "ddtrace.h"
#include "mt19937/mt19937-64.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

void ddtrace_seed_prng(void) {
    unsigned long long seed;
    if (get_DD_TRACE_DEBUG_PRNG_SEED() > 0) {
        seed = get_DD_TRACE_DEBUG_PRNG_SEED();
    } else if (php_random_int_silent(ZEND_LONG_MIN, ZEND_LONG_MAX, (zend_long *)&seed) == FAILURE) {
        seed = GENERATE_SEED();
    }
    init_genrand64(seed);
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

ddtrace_trace_id ddtrace_parse_userland_trace_id(zval *zid) {
    if (!zid || Z_TYPE_P(zid) != IS_STRING) {
        return (ddtrace_trace_id){ 0 };
    }
    ddtrace_trace_id num = {0};
    const char *id = Z_STRVAL_P(zid);
    for (size_t i = 0; i < Z_STRLEN_P(zid); i++) {
        if (id[i] < '0' || id[i] > '9') {
            return (ddtrace_trace_id){ 0 };
        }
        uint8_t digit = id[i] - '0';
        // num * 10 + digit

        // split num.low * 10 into (num.low % 2^32) * 10 + floor(num.low / 2^32) * 10 to operate on sizes fitting into uint64_t
        uint64_t carry = (((((num.low & UINT32_MAX) * 10) >> 32) + (num.low >> 32)) * 10) >> 32;
        num.low *= 10;
        num.high += carry;

        if (UNEXPECTED(UINT64_MAX - digit < num.low)) {
            ++num.high;
        }
        num.low += digit;
    }
    return num;
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

    char buf[17];
    size_t num_len = MIN(len, 16);
    memcpy(buf, id + MAX(0, (ssize_t)len - 16), num_len);
    buf[num_len] = 0;

    errno = 0;
    uint64_t uid = (uint64_t)strtoull(buf, NULL, 16);
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
    ddtrace_span_data *span = DDTRACE_G(active_stack) ? DDTRACE_G(active_stack)->active : NULL;
    return span ? span->span_id : DDTRACE_G(distributed_parent_trace_id);
}

ddtrace_trace_id ddtrace_peek_trace_id(void) {
    ddtrace_span_data *span = DDTRACE_G(active_stack) ? DDTRACE_G(active_stack)->active : NULL;
    return span ? span->trace_id : DDTRACE_G(distributed_trace_id);
}

int ddtrace_conv10_trace_id(ddtrace_trace_id id, uint8_t reverse[DD_TRACE_MAX_ID_LEN]) {
    reverse[0] = 0;
    int i = 0;
    while (id.high) {
        // (high << 64 | (low & ~((1 << 32) - 1)) | (low & ((1 << 32) - 1))) / 10
        // = (high / 10 << 64) | ((rem + (low & ~((1 << 32) - 1))) / 10) << 32 | ((rem + (low & ((1 << 32) - 1))) / 10) + rem / 10
        uint64_t high = id.high;
        id.high /= 10;
        uint64_t rem = high - id.high * 10;
        uint64_t mid = (id.low >> 32) | (rem << 32);
        uint64_t div_mid = mid / 10;
        rem = mid - div_mid * 10;
        uint64_t low = (id.low & UINT32_MAX) | (rem << 32);
        id.low = low / 10;
        rem = low - id.low * 10;
        id.low += div_mid << 32;
        reverse[++i] = '0' + rem;
    }
    while (id.low) {
        uint64_t low = id.low;
        id.low /= 10;
        uint64_t rem = low - id.low * 10;
        reverse[++i] = '0' + rem;
    }
    if (UNEXPECTED(i == 0)) {
        reverse[++i] = '0';
    }
    return i;
}
