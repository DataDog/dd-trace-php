#ifndef DATADOG_SIDECAR_H
#define DATADOG_SIDECAR_H
#include <components-rs/common.h>
#include <components/log/log.h>
#include <zai_string/string.h>
#include "datadog_export.h"
#include <tracer/ddtrace.h>
#include "zend_string.h"

// Connection mode tracking
typedef enum {
    DD_SIDECAR_CONNECTION_NONE = 0,
    DD_SIDECAR_CONNECTION_SUBPROCESS = 1,
    DD_SIDECAR_CONNECTION_THREAD = 2
} dd_sidecar_active_mode_t;

static inline bool datadog_is_empty_session_id(uint8_t id[36]) {
    return memcmp(id, "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0", 36) == 0;
}

// datadog_sidecar_instance_id is a process global — one identity per PHP process.
extern struct ddog_InstanceId *datadog_sidecar_instance_id;
// Best-effort pointer used only by the signal handler (SIGTERM/SIGINT), which cannot call
// TSRMLS_FETCH() safely.  Set to the first thread's connection; never cleared until MSHUTDOWN.
// Not atomic: concurrent shutdown is a pre-existing best-effort race for signal handlers.
extern ddog_SidecarTransport *datadog_sidecar_for_signal;
extern ddog_Endpoint *datadog_endpoint;
extern dd_sidecar_active_mode_t datadog_sidecar_active_mode;
extern int32_t datadog_sidecar_master_pid;

DATADOG_PUBLIC const uint8_t *datadog_get_formatted_session_id(void);
struct telemetry_rc_info {
    const char *rc_path;
    zend_string *service_name;
    zend_string *env_name;
    // caller does not own the data
};
DATADOG_PUBLIC struct telemetry_rc_info datadog_get_telemetry_rc_info(void);

// Connection functions
ddog_SidecarTransport *datadog_sidecar_connect(bool is_fork);

// Lifecycle functions
void datadog_sidecar_minit(void);
void datadog_sidecar_setup(ddog_RemoteConfigFlags flags);
void datadog_sidecar_handle_fork(void);
bool datadog_sidecar_should_enable(ddog_RemoteConfigFlags *flags);
void datadog_sidecar_ensure_active(void);
void datadog_sidecar_update_process_tags(void);
void datadog_sidecar_finalize(bool clear_id);
void datadog_sidecar_shutdown(void);
void datadog_force_new_instance_id(void);
void datadog_sidecar_push_tag(ddog_Vec_Tag *vec, ddog_CharSlice key, ddog_CharSlice value);
void datadog_sidecar_push_tags(ddog_Vec_Tag *vec, zval *tags);
ddog_Endpoint *datadog_sidecar_agent_endpoint(void);
void ddtrace_sidecar_submit_span_data_direct_defaults(ddog_SidecarTransport **transport, ddtrace_span_data *root);
void ddtrace_sidecar_submit_span_data_direct(ddog_SidecarTransport **transport, ddtrace_span_data *root, zend_string *cfg_service, zend_string *cfg_env, zend_string *cfg_version);

void datadog_sidecar_activate(void);
void datadog_sidecar_rinit(void);
void datadog_sidecar_rshutdown(void);
void datadog_sidecar_gshutdown(zend_datadog_globals *datadog_globals);

void datadog_sidecar_dogstatsd_count(zend_string *metric, zend_long value, zval *tags);
void datadog_sidecar_dogstatsd_distribution(zend_string *metric, double value, zval *tags);
void datadog_sidecar_dogstatsd_gauge(zend_string *metric, double value, zval *tags);
void datadog_sidecar_dogstatsd_histogram(zend_string *metric, double value, zval *tags);
void datadog_sidecar_dogstatsd_set(zend_string *metric, zend_long value, zval *tags);

bool datadog_alter_test_session_token(zval *old_value, zval *new_value, zend_string *new_str);

ddog_crasht_Metadata datadog_setup_crashtracking_metadata(ddog_Vec_Tag *tags);

#endif // DATADOG_SIDECAR_H
