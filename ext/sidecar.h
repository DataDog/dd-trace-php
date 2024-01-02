#include <components-rs/common.h>
#include <components/log/log.h>

extern ddog_SidecarTransport *ddtrace_sidecar;
extern ddog_Endpoint *ddtrace_endpoint;
extern struct ddog_InstanceId *ddtrace_sidecar_instance_id;

void ddtrace_sidecar_setup(void);
void ddtrace_sidecar_ensure_active(void);
void ddtrace_sidecar_shutdown(void);
void ddtrace_reset_sidecar_globals(void);
void ddtrace_sidecar_submit_root_span_data(void);

void ddtrace_sidecar_dogstatsd_count(zend_string *metric, zend_long value, zval *tags);
void ddtrace_sidecar_dogstatsd_distribution(zend_string *metric, double value, zval *tags);
void ddtrace_sidecar_dogstatsd_gauge(zend_string *metric, double value, zval *tags);
void ddtrace_sidecar_dogstatsd_histogram(zend_string *metric, double value, zval *tags);
void ddtrace_sidecar_dogstatsd_set(zend_string *metric, zend_long value, zval *tags);

static inline ddog_CharSlice dd_zend_string_to_CharSlice(zend_string *str) {
    return (ddog_CharSlice){ .len = str->len, .ptr = str->val };
}

static inline zend_string *dd_CharSlice_to_zend_string(ddog_CharSlice slice) {
    return zend_string_init(slice.ptr, slice.len, 0);
}

static inline ddog_CharSlice dd_zai_string_to_CharSlice(zai_string str) {
    return (ddog_CharSlice){ .len = str.len, .ptr = str.ptr };
}

static inline zend_string *dd_CharSlice_to_zend_string(ddog_CharSlice str) {
    return zend_string_init(str.ptr, str.len, 0);
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

static inline bool ddtrace_exception_debugging_is_active(void) {
    return ddtrace_sidecar_instance_id;
}
