#define _CRASHTRACKING_COLLECTOR

#include "datadog.h"
#include "sidecar.h"

#include "version.h"
#include "crashtracking_windows.h"

#include <components/log/log.h>

#include <components-rs/common.h>
#include <components-rs/crashtracker.h>
#include <components-rs/datadog.h>
#include <components-rs/sidecar.h>

bool datadog_init_crash_tracking(void) {
    ddog_Vec_Tag tags = ddog_Vec_Tag_new();
    const ddog_crasht_Metadata metadata = datadog_setup_crashtracking_metadata(&tags);

    ddog_Endpoint* agent_endpoint = datadog_sidecar_agent_endpoint();
    bool result = ddog_setup_crashtracking(agent_endpoint, metadata);

    if (result) {
        LOG(TRACE, "Crashtracking is initialized");
    } else {
        LOG(WARN, "An error occurred while initializing crashtracking");
    }

    ddog_endpoint_drop(agent_endpoint);
    ddog_Vec_Tag_drop(tags);

    return result;
}
