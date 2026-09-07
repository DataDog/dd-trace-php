#ifndef DD_LIVE_DEBUGGER_H
#define DD_LIVE_DEBUGGER_H

#include <components-rs/live-debugger.h>
#include <Zend/zend_types.h>

extern ddog_LiveDebuggerSetup ddtrace_live_debugger_setup;

void ddtrace_live_debugger_minit(void);
void ddtrace_live_debugger_rinit(void);
void ddtrace_live_debugger_rshutdown(void);
bool ddtrace_alter_dynamic_instrumentation_config(zval *old_value, zval *new_value, zend_string *new_str);

static inline void ddtrace_snapshot_redacted_name(ddog_CaptureValue *capture_value, ddog_CharSlice name) {
    if (ddog_snapshot_redacted_name(name)) {
        capture_value->not_captured_reason = DDOG_CHARSLICE_C("redactedIdent");
    }
}

static inline ddog_CharSlice ddtrace_capture_bound_charslice(ddog_CharSlice slice, uint32_t max_length) {
    if (slice.len > max_length) {
        slice.len = max_length;
    }
    return slice;
}

struct dd_refcounted_linked {
    struct dd_refcounted_linked *next;
    zend_refcounted *value;
};
void dd_free_capture_ephemerals(struct dd_refcounted_linked *ephemerals);

void ddtrace_sidecar_send_debugger_data(ddog_Vec_DebuggerPayload payloads);
void ddtrace_sidecar_send_debugger_datum(ddog_DebuggerPayload *payload);

void dd_start_debugger_timeout(void);
void dd_stop_debugger_timeout(void);
#ifndef _WIN32
void ddtrace_live_debugger_handle_sigvtalarm(void);
#endif

// The capture is aborted once its approximate serialized size exceeds this; larger snapshots are of
// little use and risk being rejected by the intake.
#define DD_MAX_CAPTURE_SIZE (1024 * 1024)

// Per captured value serialization overhead: the JSON scaffolding around it plus its (field) name.
#define DD_CAPTURE_VALUE_OVERHEAD 48

void ddtrace_increase_capture_size(size_t bytes);

// Which of the two independent triggers set debugger_capture_timed_out; recorded by whichever
// trigger fires first, since the CPU timer and the size limit share that one abort flag.
#define DD_CAPTURE_ABORT_NONE 0
#define DD_CAPTURE_ABORT_TIMEOUT 1
#define DD_CAPTURE_ABORT_SIZE 2

#endif // DD_LIVE_DEBUGGER_H
