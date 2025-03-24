#include "trace_source.h"

#include "configuration.h"
#include "ddtrace.h"
#include "priority_sampling/priority_sampling.h"
#include "tracer_tag_propagation/tracer_tag_propagation.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

#define TRACE_SOURCE_APM (1 << 0)
#define TRACE_SOURCE_ASM (1 << 1)
#define TRACE_SOURCE_DSM (1 << 3)
#define TRACE_SOURCE_DJM (1 << 7)
#define TRACE_SOURCE_DBM (1 << 9)

static zend_string *_dd_tag_p_ts;

void ddtrace_trace_source_minit() {
    _dd_tag_p_ts = zend_string_init_interned(ZEND_STRL(DD_P_TS_KEY), 1 /* permanent */);
}

void ddtrace_trace_source_rinit() {
    DDTRACE_G(products_bm) = 0;
}

zend_string *ddtrace_trace_source_get_ts_encoded() {
    zend_string *str = zend_string_alloc(2, 0);
    snprintf(ZSTR_VAL(str), 3, "%02" PRIx64, DDTRACE_G(products_bm));
    return str;
}

void ddtrace_trace_source_set_from_string(zend_string *hexadecimal_string)
{
    DDTRACE_G(products_bm) = strtol(ZSTR_VAL(hexadecimal_string), NULL, 16);
}

static void ddtrace_trace_source_add_propagated_tag() {
    zend_string *str = ddtrace_trace_source_get_ts_encoded();
    zval prodcts_bm_coded_zv;
    ZVAL_STR(&prodcts_bm_coded_zv, str);
    ddtrace_add_propagated_tag(_dd_tag_p_ts, &prodcts_bm_coded_zv);
    zend_string_release(str);
}

void ddtrace_trace_source_set_asm() {
    if (DDTRACE_G(products_bm) & TRACE_SOURCE_ASM) {
        return;
    }
    DDTRACE_G(products_bm) |= TRACE_SOURCE_ASM;
    ddtrace_trace_source_add_propagated_tag();
}

bool ddtrace_trace_source_is_asm_source() {
    return DDTRACE_G(products_bm) & TRACE_SOURCE_ASM;
}


