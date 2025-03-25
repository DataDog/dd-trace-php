#include "trace_source.h"

#include "configuration.h"
#include "ddtrace.h"
#include "priority_sampling/priority_sampling.h"
#include "tracer_tag_propagation/tracer_tag_propagation.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#define TRACE_SOURCE_APM (1 << 0)
#define TRACE_SOURCE_ASM (1 << 1)
#define TRACE_SOURCE_DSM (1 << 2)
#define TRACE_SOURCE_DJM (1 << 3)
#define TRACE_SOURCE_DBM (1 << 4)

static zend_string *_dd_tag_p_ts;

void ddtrace_trace_source_minit() {
    _dd_tag_p_ts = zend_string_init_interned(ZEND_STRL(DD_P_TS_KEY), 1 /* permanent */);
}

void ddtrace_trace_source_rinit() {
    DDTRACE_G(trace_source_bm) = 0;
}

zend_string *ddtrace_trace_source_get_encoded() {
    zend_string *encoded = zend_string_alloc(2, 0);
    snprintf(ZSTR_VAL(encoded), 3, "%02x" PRIx64, DDTRACE_G(trace_source_bm));
    return encoded;
}

bool ddtrace_trace_source_set_from_hexadecimal(zend_string *hexadecimal)
{
    if (!hexadecimal || ZSTR_LEN(hexadecimal) < 2 || ZSTR_LEN(hexadecimal) > 8) {
        return false;
    }
    char *endptr;
    DDTRACE_G(trace_source_bm) = strtol(ZSTR_VAL(hexadecimal), &endptr, 16);

    return *endptr == '\0';
}

static void ddtrace_trace_source_add_propagated_tag() {
    zend_string *encoded = ddtrace_trace_source_get_encoded();
    zval ts_encoded_zv;
    ZVAL_STR(&ts_encoded_zv, encoded);
    ddtrace_add_propagated_tag(_dd_tag_p_ts, &ts_encoded_zv);
    zend_string_release(encoded);
}

void ddtrace_trace_source_set_asm_source() {
    if (DDTRACE_G(trace_source_bm) & TRACE_SOURCE_ASM) {
        return;
    }
    DDTRACE_G(trace_source_bm) |= TRACE_SOURCE_ASM;
    ddtrace_trace_source_add_propagated_tag();
}

bool ddtrace_trace_source_is_asm_source() {
    return DDTRACE_G(trace_source_bm) & TRACE_SOURCE_ASM;
}


