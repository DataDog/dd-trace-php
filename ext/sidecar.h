#include <components-rs/common.h>

extern ddog_SidecarTransport *dd_sidecar;
extern struct ddog_InstanceId *dd_sidecar_instance_id;

void ddtrace_sidecar_setup(void);
void ddtrace_sidecar_ensure_active(void);
void ddtrace_sidecar_shutdown(void);
void ddtrace_reset_sidecar_globals(void);

static inline ddog_CharSlice dd_zend_string_to_CharSlice(zend_string *str) {
    return (ddog_CharSlice){ .len = str->len, .ptr = str->val };
}
