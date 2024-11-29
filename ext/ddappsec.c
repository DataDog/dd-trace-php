#include "ddappsec.h"

#include "ddtrace.h"
#include "tracer_tag_propagation/tracer_tag_propagation.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

static zend_string *_dd_tag_p_appsec_zstr;
static zend_string *_1_zstr;

void ddtrace_appsec_minit() {
    _1_zstr = zend_string_init_interned(ZEND_STRL("1"), 1 /* permanent */);
    _dd_tag_p_appsec_zstr = zend_string_init_interned(ZEND_STRL(DD_TAG_P_APPSEC), 1 /* permanent */);
}

DDTRACE_PUBLIC void ddtrace_emit_asm_event() {
    DDTRACE_G(asm_event_emitted) = true;

    zval _1_zval;
    ZVAL_STR(&_1_zval, _1_zstr);
    ddtrace_add_propagated_tag(_dd_tag_p_appsec_zstr, &_1_zval);
}

PHP_FUNCTION(DDTrace_Testing_emit_asm_event) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    ddtrace_emit_asm_event();
}