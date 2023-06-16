#include "ddtrace.h"
#include "configuration.h"
#include "coms.h"
#include "logging.h"
#include <components-rs/ddtrace.h>
#include <components/log/log.h>
#include "sidecar.h"
#include "telemetry.h"

ddog_SidecarTransport *dd_sidecar;
struct ddog_InstanceId *dd_sidecar_instance_id;
static uint8_t dd_sidecar_formatted_session_id[36];

static void ddtrace_set_sidecar_globals(void) {
    uint8_t formatted_run_time_id[36];
    ddtrace_format_runtime_id(&formatted_run_time_id);
    ddog_CharSlice runtime_id = (ddog_CharSlice) {.ptr = (char *) formatted_run_time_id, .len = sizeof(formatted_run_time_id)};
    ddog_CharSlice session_id = (ddog_CharSlice) {.ptr = (char *) dd_sidecar_formatted_session_id, .len = sizeof(dd_sidecar_formatted_session_id)};
    dd_sidecar_instance_id = ddog_sidecar_instanceId_build(session_id, runtime_id);
}

static bool dd_sidecar_connection_init(void) {
    ddog_Option_VecU8 sidecar_error = ddog_sidecar_connect_php(&dd_sidecar);
    if (sidecar_error.tag == DDOG_OPTION_VEC_U8_SOME_VEC_U8) {
        LOG(Error, "%.*s", (int)sidecar_error.some.len, sidecar_error.some.ptr);
        ddog_MaybeError_drop(sidecar_error);
        dd_sidecar = NULL;
        return false;
    }

    char *agent_url = ddtrace_agent_url();
    ddog_Endpoint *endpoint = ddog_endpoint_from_url((ddog_CharSlice){ .ptr = agent_url, .len = strlen(agent_url) });
    free(agent_url);
    if (!endpoint) {
        ddog_sidecar_transport_drop(dd_sidecar);
        return false;
    }

    if (!dd_sidecar_instance_id) {
        ddtrace_format_runtime_id(&dd_sidecar_formatted_session_id);
        ddtrace_set_sidecar_globals();

        ddtrace_telemetry_first_init();
    }

    ddog_CharSlice session_id = (ddog_CharSlice) {.ptr = (char *) dd_sidecar_formatted_session_id, .len = sizeof(dd_sidecar_formatted_session_id)};
    ddog_sidecar_session_set_config(&dd_sidecar, session_id, endpoint,
                                    get_global_DD_TRACE_AGENT_FLUSH_INTERVAL(),
                                    get_global_DD_TRACE_AGENT_STACK_INITIAL_SIZE(),
                                    get_global_DD_TRACE_AGENT_STACK_BACKLOG() * get_global_DD_TRACE_AGENT_MAX_PAYLOAD_SIZE());

    ddog_endpoint_drop(endpoint);

    return true;
}

void ddtrace_sidecar_setup(void) {
    dd_sidecar_connection_init();
}

void ddtrace_sidecar_ensure_active(void) {
    if (!dd_sidecar || ddog_sidecar_is_closed(&dd_sidecar)) {
        if (dd_sidecar) {
            ddog_sidecar_transport_drop(dd_sidecar);
        }
        dd_sidecar_connection_init();
    }
}

void ddtrace_sidecar_shutdown(void) {
    if (dd_sidecar_instance_id) {
        ddog_sidecar_instanceId_drop(dd_sidecar_instance_id);
    }
    if (dd_sidecar) {
        ddog_sidecar_transport_drop(dd_sidecar);
    }
}

void ddtrace_reset_sidecar_globals(void) {
    if (dd_sidecar_instance_id) {
        ddog_sidecar_instanceId_drop(dd_sidecar_instance_id);
        ddtrace_set_sidecar_globals();
    }
}
