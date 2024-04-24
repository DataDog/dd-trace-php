#include <components-rs/common.h>
#include <components/log/log.h>

extern ddog_SidecarTransport *ddtrace_sidecar;
extern ddog_Endpoint *ddtrace_endpoint;
extern struct ddog_InstanceId *ddtrace_sidecar_instance_id;

void ddtrace_sidecar_setup(void);
void ddtrace_sidecar_ensure_active(void);
void ddtrace_sidecar_shutdown(void);
void ddtrace_reset_sidecar_globals(void);

static inline ddog_CharSlice dd_zend_string_to_CharSlice(zend_string *str) {
    return (ddog_CharSlice){ .len = str->len, .ptr = str->val };
}

static inline ddog_CharSlice dd_zai_string_to_CharSlice(zai_string str) {
    return (ddog_CharSlice){ .len = str.len, .ptr = str.ptr };
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
