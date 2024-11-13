#ifndef DD_RANDOM_H
#define DD_RANDOM_H
#include <Zend/zend_types.h>
#include <php.h>
#include <stdbool.h>
#include <stdint.h>

#include "compatibility.h"
#include "ddtrace.h"

#define DD_TRACE_MAX_ID_LEN 40  // uint64_t -> 2**128 = 20 chars max ID

void ddtrace_seed_prng(void);
bool ddtrace_reseed_seed_change(zval *old_value, zval *new_value, zend_string *new_str);
uint64_t ddtrace_generate_span_id(void);
uint64_t ddtrace_peek_span_id(void);
ddtrace_trace_id ddtrace_peek_trace_id(void);
uint64_t ddtrace_parse_userland_span_id(zval *zid);
ddtrace_trace_id ddtrace_parse_userland_trace_id(zend_string *tid);
ddtrace_trace_id ddtrace_parse_hex_trace_id(char *trace_id, ssize_t trace_id_len);
uint64_t ddtrace_parse_hex_span_id_str(const char *id, size_t len);
uint64_t ddtrace_parse_hex_span_id(zval *zid);
int ddtrace_conv10_trace_id(ddtrace_trace_id id, uint8_t reverse[DD_TRACE_MAX_ID_LEN]);

#endif  // DD_RANDOM_H
