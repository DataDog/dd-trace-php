#ifndef DD_SIDECAR_H
#define DD_SIDECAR_H
#include <components-rs/common.h>
#include <components/log/log.h>
#include <zai_string/string.h>

extern ddog_SidecarTransport *ddtrace_sidecar;
extern ddog_Endpoint *ddtrace_endpoint;
extern struct ddog_InstanceId *ddtrace_sidecar_instance_id;

void ddtrace_sidecar_setup(void);
void ddtrace_sidecar_ensure_active(void);
void ddtrace_sidecar_shutdown(void);
void ddtrace_reset_sidecar_globals(void);
void ddtrace_sidecar_ensure_root_span_data_submitted(void);
void ddtrace_sidecar_submit_root_span_data(void);
void ddtrace_sidecar_push_tag(ddog_Vec_Tag *vec, ddog_CharSlice key, ddog_CharSlice value);
void ddtrace_sidecar_push_tags(ddog_Vec_Tag *vec, zval *tags);
ddog_Endpoint *ddtrace_sidecar_agent_endpoint(void);

void ddtrace_sidecar_send_debugger_data(ddog_Vec_DebuggerPayload payloads);
void ddtrace_sidecar_send_debugger_datum(ddog_DebuggerPayload *payload);

void ddtrace_sidecar_rinit(void);
void ddtrace_sidecar_rshutdown(void);

void ddtrace_sidecar_dogstatsd_count(zend_string *metric, zend_long value, zval *tags);
void ddtrace_sidecar_dogstatsd_distribution(zend_string *metric, double value, zval *tags);
void ddtrace_sidecar_dogstatsd_gauge(zend_string *metric, double value, zval *tags);
void ddtrace_sidecar_dogstatsd_histogram(zend_string *metric, double value, zval *tags);
void ddtrace_sidecar_dogstatsd_set(zend_string *metric, zend_long value, zval *tags);

bool ddtrace_alter_test_session_token(zval *old_value, zval *new_value, zend_string *new_str);

static inline ddog_CharSlice dd_zend_string_to_CharSlice(zend_string *str) {
    return (ddog_CharSlice){ .len = str->len, .ptr = str->val };
}

static inline ddog_CharSlice dd_zai_string_to_CharSlice(zai_string str) {
    return (ddog_CharSlice){ .len = str.len, .ptr = str.ptr };
}

static inline zend_string *dd_CharSlice_to_zend_string(ddog_CharSlice slice) {
    return zend_string_init(slice.ptr, slice.len, 0);
}

static inline bool ddtrace_ffi_try(const char *msg, ddog_MaybeError maybe_error) {
    if (maybe_error.tag == DDOG_OPTION_ERROR_SOME_ERROR) {
        ddog_CharSlice error = ddog_Error_message(&maybe_error.some);
        LOG(ERROR, "%s: %.*s", msg, (int) error.len, error.ptr);
        ddog_MaybeError_drop(maybe_error);
        return false;
    }
    return true;
}

bool ddtrace_exception_debugging_is_active(void);

#endif // DD_SIDECAR_H
