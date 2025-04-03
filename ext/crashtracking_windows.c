#define _CRASHTRACKING_COLLECTOR

#include "ddtrace.h"
#include "sidecar.h"

#include "version.h"
#include "crashtracking_windows.h"

#include <components/log/log.h>

#include <components-rs/common.h>
#include <components-rs/crashtracker.h>
#include <components-rs/ddtrace.h>
#include <components-rs/sidecar.h>

bool init_crash_tracking(void) {
    ddog_Vec_Tag tags = ddog_Vec_Tag_new();
    const ddog_crasht_Metadata metadata = ddtrace_setup_crashtracking_metadata(&tags);

    ddog_Endpoint* agent_endpoint = ddtrace_sidecar_agent_endpoint();
    bool result = ddog_setup_crashtracking(agent_endpoint, metadata);

    if (result) {
        LOG(TRACE, "Crashtracking is initialized");
    } else {
        LOG(WARN, "An error occured while initializing crashtracking");
    }

    ddog_endpoint_drop(agent_endpoint);
    ddog_Vec_Tag_drop(tags);

    return result;
}
