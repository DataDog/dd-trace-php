#include "asm_event.h"

#include "configuration.h"
#include "ddtrace.h"
#include "trace_source.h"
#include "priority_sampling/priority_sampling.h"
#include "tracer_tag_propagation/tracer_tag_propagation.h"

ZEND_EXTERN_MODULE_GLOBALS(ddtrace);

DDTRACE_PUBLIC void ddtrace_emit_asm_event() {
    if (!DDTRACE_G(active_stack)) {
        return;
    }
    if (DDTRACE_G(active_stack)->root_span) {
        DDTRACE_G(active_stack)->root_span->asm_event_emitted = true;
        ddtrace_trace_source_set_asm_source();
    } else {
        DDTRACE_G(asm_event_emitted) = true;
    }

    ddtrace_set_priority_sampling_on_root(PRIORITY_SAMPLING_USER_KEEP, get_DD_APM_TRACING_ENABLED() ? DD_MECHANISM_MANUAL : DD_MECHANISM_ASM);
}

PHP_FUNCTION(DDTrace_Testing_emit_asm_event) {
    if (zend_parse_parameters_none() == FAILURE) {
        RETURN_THROWS();
    }

    ddtrace_emit_asm_event();
}

bool ddtrace_asm_event_emitted() {
    return DDTRACE_G(asm_event_emitted) || (DDTRACE_G(active_stack) && DDTRACE_G(active_stack)->root_span && DDTRACE_G(active_stack)->root_span->asm_event_emitted);
}

void ddtrace_asm_event_rinit() {
    DDTRACE_G(asm_event_emitted) = false;
}
