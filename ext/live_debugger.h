#ifndef DD_LIVE_DEBUGGER_H
#define DD_LIVE_DEBUGGER_H

#include <components-rs/live-debugger.h>

extern ddog_LiveDebuggerSetup ddtrace_live_debugger_setup;

void ddtrace_live_debugger_minit(void);

static inline void ddtrace_snapshot_redacted_name(ddog_CaptureValue *capture_value, ddog_CharSlice name) {
    if (ddog_snapshot_redacted_name(name)) {
        capture_value->not_captured_reason = DDOG_CHARSLICE_C("redactedIdent");
    }
}

#endif // DD_LIVE_DEBUGGER_H
