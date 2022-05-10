#ifndef DD_RANDOM_H
#define DD_RANDOM_H
#include <Zend/zend_types.h>
#include <php.h>
#include <stdint.h>

#define DD_TRACE_MAX_ID_LEN 20  // uint64_t -> 2**64 = 20 chars max ID

void ddtrace_seed_prng(TSRMLS_D);
uint64_t ddtrace_generate_span_id(void);
uint64_t ddtrace_peek_span_id(TSRMLS_D);
uint64_t ddtrace_peek_trace_id(TSRMLS_D);
uint64_t ddtrace_parse_userland_span_id(zval *zid);

#endif  // DD_RANDOM_H
