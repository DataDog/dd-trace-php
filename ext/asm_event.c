#include "asm_event.h"

#include "configuration.h"
#include "ddtrace.h"
#include "priority_sampling/priority_sampling.h"
#include "tracer_tag_propagation/tracer_tag_propagation.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static zend_string *_dd_tag_p_ts;

void ddtrace_asm_event_minit() {
    _dd_tag_p_ts = zend_string_init_interned(ZEND_STRL(DD_P_TS_KEY), 1 /* permanent */);
}

DDTRACE_PUBLIC void ddtrace_emit_asm_event() {
    if (!DDTRACE_G(active_stack)) {
        return;
    }
    DDTRACE_G(asm_event_emitted) = true;
    if (!(DDTRACE_G(products_bm) & TRACE_SOURCE_ASM)) {
        DDTRACE_G(products_bm) |= TRACE_SOURCE_ASM;

        zend_string *str = zend_string_alloc(2, 0);
        snprintf(ZSTR_VAL(str), 3, "%02" PRIx64, DDTRACE_G(products_bm));
        zval prodcts_bm_coded_zv;
        ZVAL_STR(&prodcts_bm_coded_zv, str);
        ddtrace_add_propagated_tag(_dd_tag_p_ts, &prodcts_bm_coded_zv);
        zend_string_release(str);
    }
    if (!get_DD_APM_TRACING_ENABLED()) {
        ddtrace_set_priority_sampling_on_root(PRIORITY_SAMPLING_USER_KEEP, DD_MECHANISM_ASM);
    }
}

PHP_FUNCTION(DDTrace_Testing_emit_asm_event) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    ddtrace_emit_asm_event();
}