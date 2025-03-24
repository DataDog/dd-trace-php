#include "asm_event.h"

#include "configuration.h"
#include "ddtrace.h"
#include "priority_sampling/priority_sampling.h"
#include "tracer_tag_propagation/tracer_tag_propagation.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

DDTRACE_PUBLIC void ddtrace_emit_asm_event() {
    if (!DDTRACE_G(active_stack)) {
        return;
    }
    DDTRACE_G(asm_event_emitted) = true;
    DDTRACE_G(products_bm) |= DD_P_TS_APPSEC;
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