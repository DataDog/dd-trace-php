#ifndef DD_RANDOM_H
#define DD_RANDOM_H
#include <Zend/zend_types.h>
#include <php.h>
#include <stdbool.h>
#include <stdint.h>

#include "compatibility.h"

#define DD_TRACE_MAX_ID_LEN 20  // uint64_t -> 2**64 = 20 chars max ID

void ddtrace_seed_prng(void);
uint64_t ddtrace_generate_span_id(void);
uint64_t ddtrace_peek_span_id(void);
uint64_t ddtrace_peek_trace_id(void);
uint64_t ddtrace_parse_userland_span_id(zval *zid);
uint64_t ddtrace_parse_hex_span_id_str(const char *id, size_t len);
uint64_t ddtrace_parse_hex_span_id(zval *zid);

#endif  // DD_RANDOM_H
