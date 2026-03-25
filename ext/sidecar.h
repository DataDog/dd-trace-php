#ifndef DD_SIDECAR_H
#define DD_SIDECAR_H
#include <components-rs/common.h>
#include <components/log/log.h>
#include <zai_string/string.h>
#include "ddtrace_export.h"
#include "ddtrace.h"
#include "zend_string.h"

// Connection mode tracking
typedef enum {
    DD_SIDECAR_CONNECTION_NONE = 0,
    DD_SIDECAR_CONNECTION_SUBPROCESS = 1,
    DD_SIDECAR_CONNECTION_THREAD = 2
} dd_sidecar_active_mode_t;

extern ddog_SidecarTransport *ddtrace_sidecar;
extern ddog_Endpoint *ddtrace_endpoint;
extern struct ddog_InstanceId *ddtrace_sidecar_instance_id;
extern dd_sidecar_active_mode_t ddtrace_sidecar_active_mode;
extern int32_t ddtrace_sidecar_master_pid;

DDTRACE_PUBLIC const uint8_t *ddtrace_get_formatted_session_id(void);
struct telemetry_rc_info {
    const char *rc_path;
    zend_string *service_name;
    zend_string *env_name;
    // caller does not own the data
};
DDTRACE_PUBLIC struct telemetry_rc_info ddtrace_get_telemetry_rc_info(void);

// Connection functions
ddog_SidecarTransport *ddtrace_sidecar_connect(bool is_fork);

// Lifecycle functions
void ddtrace_sidecar_minit(void);
void ddtrace_sidecar_setup(bool appsec_activation, bool appsec_config);
void ddtrace_sidecar_handle_fork(void);
bool ddtrace_sidecar_maybe_enable_appsec(bool *appsec_activation, bool *appsec_config);
bool ddtrace_sidecar_should_enable(bool *appsec_activation, bool *appsec_config);
void ddtrace_sidecar_ensure_active(void);
void ddtrace_sidecar_update_process_tags(void);
void ddtrace_sidecar_finalize(bool clear_id);
void ddtrace_sidecar_shutdown(void);
void ddtrace_force_new_instance_id(void);
void ddtrace_sidecar_submit_root_span_data(void);
void ddtrace_sidecar_push_tag(ddog_Vec_Tag *vec, ddog_CharSlice key, ddog_CharSlice value);
void ddtrace_sidecar_push_tags(ddog_Vec_Tag *vec, zval *tags);
ddog_Endpoint *ddtrace_sidecar_agent_endpoint(void);
void ddtrace_sidecar_submit_root_span_data_direct_defaults(ddog_SidecarTransport **transport, ddtrace_root_span_data *root);
void ddtrace_sidecar_submit_root_span_data_direct(ddog_SidecarTransport **transport, ddtrace_root_span_data *root, zend_string *cfg_service, zend_string *cfg_env, zend_string *cfg_version);

void ddtrace_sidecar_send_debugger_data(ddog_Vec_DebuggerPayload payloads);
void ddtrace_sidecar_send_debugger_datum(ddog_DebuggerPayload *payload);

void ddtrace_sidecar_activate(void);
void ddtrace_sidecar_rinit(void);
void ddtrace_sidecar_rshutdown(void);

void ddtrace_sidecar_dogstatsd_count(zend_string *metric, zend_long value, zval *tags);
void ddtrace_sidecar_dogstatsd_distribution(zend_string *metric, double value, zval *tags);
void ddtrace_sidecar_dogstatsd_gauge(zend_string *metric, double value, zval *tags);
void ddtrace_sidecar_dogstatsd_histogram(zend_string *metric, double value, zval *tags);
void ddtrace_sidecar_dogstatsd_set(zend_string *metric, zend_long value, zval *tags);

bool ddtrace_alter_test_session_token(zval *old_value, zval *new_value, zend_string *new_str);

static inline ddog_CharSlice dd_zend_string_to_CharSlice(zend_string *str) {
    if (str == NULL) {
        return (ddog_CharSlice){ .len = 0, .ptr = NULL };
    }
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
ddog_crasht_Metadata ddtrace_setup_crashtracking_metadata(ddog_Vec_Tag *tags);

#endif // DD_SIDECAR_H
