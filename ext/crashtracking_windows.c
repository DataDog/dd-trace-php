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

    ddog_Vec_Tag_push(&tags, DDOG_CHARSLICE_C("is_crash"), DDOG_CHARSLICE_C("true"));
    ddog_Vec_Tag_push(&tags, DDOG_CHARSLICE_C("severity"), DDOG_CHARSLICE_C("crash"));
    ddog_Vec_Tag_push(&tags, DDOG_CHARSLICE_C("library_version"), DDOG_CHARSLICE_C(PHP_DDTRACE_VERSION));
    ddog_Vec_Tag_push(&tags, DDOG_CHARSLICE_C("language"), DDOG_CHARSLICE_C("php"));
    ddog_Vec_Tag_push(&tags, DDOG_CHARSLICE_C("runtime"), DDOG_CHARSLICE_C("php"));

    uint8_t runtime_id[36];
    ddtrace_format_runtime_id(&runtime_id);
    ddog_Vec_Tag_push(&tags, DDOG_CHARSLICE_C("runtime-id"), (ddog_CharSlice) { .ptr = (char*)runtime_id, .len = sizeof(runtime_id) });

    const char* runtime_version = zend_get_module_version("Reflection");
    ddog_Vec_Tag_push(&tags, DDOG_CHARSLICE_C("runtime_version"), (ddog_CharSlice) { .ptr = (char*)runtime_version, .len = strlen(runtime_version) });

    const ddog_crasht_Metadata metadata = {
        .library_name = DDOG_CHARSLICE_C_BARE("dd-trace-php"),
        .library_version = DDOG_CHARSLICE_C_BARE(PHP_DDTRACE_VERSION),
        .family = DDOG_CHARSLICE_C("php"),
        .tags = &tags
    };

    ddog_Endpoint* agent_endpoint = ddtrace_sidecar_agent_endpoint();
    bool result = ddog_setup_crashtracking(agent_endpoint, metadata);

    if (result) {
        LOG(INFO, "Crashtracking is initialized");
    }
    else {
        LOG(WARN, "An error occured while initializing crashtracking");
    }

    ddog_endpoint_drop(agent_endpoint);
    ddog_Vec_Tag_drop(tags);

    return result;
}
