#include "ddtrace.h"
#include "auto_flush.h"
#include "configuration.h"
#include "dogstatsd_client.h"
#include "logging.h"
#include <components-rs/ddtrace.h>
#include "sidecar.h"
#include "telemetry.h"

ddog_SidecarTransport *ddtrace_sidecar;
ddog_Endpoint *ddtrace_endpoint;
struct ddog_InstanceId *ddtrace_sidecar_instance_id;
static uint8_t dd_sidecar_formatted_session_id[36];

static void ddtrace_set_sidecar_globals(void) {
    uint8_t formatted_run_time_id[36];
    ddtrace_format_runtime_id(&formatted_run_time_id);
    ddog_CharSlice runtime_id = (ddog_CharSlice) {.ptr = (char *) formatted_run_time_id, .len = sizeof(formatted_run_time_id)};
    ddog_CharSlice session_id = (ddog_CharSlice) {.ptr = (char *) dd_sidecar_formatted_session_id, .len = sizeof(dd_sidecar_formatted_session_id)};
    ddtrace_sidecar_instance_id = ddog_sidecar_instanceId_build(session_id, runtime_id);
}

static bool dd_sidecar_connection_init(void) {
    ddog_Endpoint *dogstatsd_endpoint;
    if (get_global_DD_TRACE_AGENTLESS() && ZSTR_LEN(get_global_DD_API_KEY())) {
        ddtrace_endpoint = ddog_endpoint_from_api_key(dd_zend_string_to_CharSlice(get_global_DD_API_KEY()));
        dogstatsd_endpoint = ddog_endpoint_empty();
    } else {
        char *agent_url = ddtrace_agent_url();
        ddtrace_endpoint = ddog_endpoint_from_url((ddog_CharSlice) {.ptr = agent_url, .len = strlen(agent_url)});
        free(agent_url);

        char *dogstatsd_url = ddtrace_dogstatsd_url();
        dogstatsd_endpoint = ddog_endpoint_from_url((ddog_CharSlice) {.ptr = dogstatsd_url, .len = strlen(dogstatsd_url)});
        free(dogstatsd_url);
    }

    if (!ddtrace_endpoint) {
        ddtrace_sidecar = NULL;
        return false;
    }

#ifdef _WIN32
    DDOG_PHP_FUNCTION = (const uint8_t *)zend_hash_func;
#endif

    char logpath[MAXPATHLEN];
    int error_fd = atomic_load(&ddtrace_error_log_fd);
    if (error_fd == -1 || ddtrace_get_fd_path(error_fd, logpath) < 0) {
        *logpath = 0;
    }

    if (!ddtrace_ffi_try("Failed connecting to the sidecar", ddog_sidecar_connect_php(&ddtrace_sidecar, logpath, dd_zend_string_to_CharSlice(get_global_DD_TRACE_LOG_LEVEL()), get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED()))) {
        ddog_endpoint_drop(ddtrace_endpoint);
        ddtrace_endpoint = NULL;
        ddtrace_sidecar = NULL;
        return false;
    }

    if (!ddtrace_sidecar_instance_id) {
        ddtrace_format_runtime_id(&dd_sidecar_formatted_session_id);
        ddtrace_set_sidecar_globals();

        if (get_global_DD_INSTRUMENTATION_TELEMETRY_ENABLED()) {
            ddtrace_telemetry_first_init();
        }
    }

    ddog_CharSlice session_id = (ddog_CharSlice) {.ptr = (char *) dd_sidecar_formatted_session_id, .len = sizeof(dd_sidecar_formatted_session_id)};
    ddog_sidecar_session_set_config(&ddtrace_sidecar, session_id, ddtrace_endpoint, dogstatsd_endpoint,
                                    get_global_DD_TRACE_AGENT_FLUSH_INTERVAL(),
                                    get_global_DD_TRACE_AGENT_STACK_INITIAL_SIZE(),
                                    get_global_DD_TRACE_AGENT_STACK_BACKLOG() * get_global_DD_TRACE_AGENT_MAX_PAYLOAD_SIZE(),
                                    get_global_DD_TRACE_DEBUG() ? DDOG_CHARSLICE_C("debug") : dd_zend_string_to_CharSlice(get_global_DD_TRACE_LOG_LEVEL()),
                                    (ddog_CharSlice){ .ptr = logpath, .len = strlen(logpath) });

    return true;
}

void ddtrace_sidecar_setup(void) {
    dd_sidecar_connection_init();
}

void ddtrace_sidecar_ensure_active(void) {
    if (!ddtrace_sidecar || ddog_sidecar_is_closed(&ddtrace_sidecar)) {
        if (ddtrace_sidecar) {
            ddog_sidecar_transport_drop(ddtrace_sidecar);
        }
        dd_sidecar_connection_init();
    }
}

void ddtrace_sidecar_shutdown(void) {
    if (ddtrace_sidecar_instance_id) {
        ddog_sidecar_instanceId_drop(ddtrace_sidecar_instance_id);
    }
    if (ddtrace_endpoint) {
        ddog_endpoint_drop(ddtrace_endpoint);
    }
    if (ddtrace_sidecar) {
        ddog_sidecar_transport_drop(ddtrace_sidecar);
    }
}

void ddtrace_reset_sidecar_globals(void) {
    if (ddtrace_sidecar_instance_id) {
        ddog_sidecar_instanceId_drop(ddtrace_sidecar_instance_id);
        ddtrace_set_sidecar_globals();
    }
}

void ddtrace_sidecar_dogstatsd_count(zend_string *metric, double value) {
    if (!ddtrace_sidecar) {
        return;
    }
    ddog_sidecar_dogstatsd_count(&ddtrace_sidecar, ddtrace_sidecar_instance_id, dd_zend_string_to_CharSlice(metric), value);
}

void ddtrace_sidecar_dogstatsd_gauge(zend_string *metric, double value) {
    if (!ddtrace_sidecar) {
        return;
    }
    ddog_sidecar_dogstatsd_gauge(&ddtrace_sidecar, ddtrace_sidecar_instance_id, dd_zend_string_to_CharSlice(metric), value);
}
