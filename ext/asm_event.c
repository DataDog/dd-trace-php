#include "asm_event.h"

#include "configuration.h"
#include "ddtrace.h"
#include "priority_sampling/priority_sampling.h"
#include "tracer_tag_propagation/tracer_tag_propagation.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static zend_string *_dd_tag_p_appsec_zstr;
static zend_string *_1_zstr;

void ddtrace_appsec_minit() {
    _1_zstr = zend_string_init_interned(ZEND_STRL("1"), 1 /* permanent */);
    _dd_tag_p_appsec_zstr = zend_string_init_interned(ZEND_STRL(DD_TAG_P_APPSEC), 1 /* permanent */);
}

DDTRACE_PUBLIC void ddtrace_emit_asm_event() {
    if (!DDTRACE_G(active_stack)) {
        return;
    }

    DDTRACE_G(asm_event_emitted) = true;

    zval _1_zval;
    ZVAL_STR(&_1_zval, _1_zstr);
    ddtrace_add_propagated_tag(_dd_tag_p_appsec_zstr, &_1_zval);

    zend_string *trace_source_zstr = zend_string_init("_dd.p.ts", sizeof("_dd.p.ts") - 1, 0);
    if (!ddtrace_propagated_tag_exists(trace_source_zstr))
    {
        zval ts_value;
        zend_string *ts_asm = zend_string_init("02", sizeof("02") - 1, 0);
        ZVAL_STR(&ts_value, ts_asm);
        ddtrace_add_propagated_tag(trace_source_zstr, &ts_value);
        zend_string_release(ts_asm);
    }
    zend_string_release(trace_source_zstr);

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