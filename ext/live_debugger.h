#ifndef DD_LIVE_DEBUGGER_H
#define DD_LIVE_DEBUGGER_H

#include <components-rs/live-debugger.h>
#include <Zend/zend_types.h>

extern ddog_LiveDebuggerSetup ddtrace_live_debugger_setup;

void ddtrace_live_debugger_minit(void);
void ddtrace_live_debugger_mshutdown(void);
bool ddtrace_alter_dynamic_instrumentation_config(zval *old_value, zval *new_value, zend_string *new_str);
ddog_DynamicInstrumentationConfigState ddtrace_dynamic_instrumentation_state(void);

static inline void ddtrace_snapshot_redacted_name(ddog_CaptureValue *capture_value, ddog_CharSlice name) {
    if (ddog_snapshot_redacted_name(name)) {
        capture_value->not_captured_reason = DDOG_CHARSLICE_C("redactedIdent");
    }
}

#endif // DD_LIVE_DEBUGGER_H
