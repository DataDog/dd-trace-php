#define _CRASHTRACKING_COLLECTOR

#include "ddtrace.h"
#include "sidecar.h"

#include "version.h"
#include "crashtracking_windows.h"
#include "process_tags.h"

#include <components/log/log.h>

#include <components-rs/common.h>
#include <components-rs/crashtracker.h>
#include <components-rs/ddtrace.h>
#include <components-rs/sidecar.h>

bool init_crash_tracking(void) {
    ddog_Vec_Tag tags = ddog_Vec_Tag_new();

    // Add process_tags if available
    zend_string *process_tags_serialized = ddtrace_process_tags_get_serialized();
    if (process_tags_serialized) {
        ddog_Vec_Tag_PushResult result = ddog_Vec_Tag_push(
            &tags,
            DDOG_CHARSLICE_C("process_tags"),
            (ddog_CharSlice) {
                .ptr = ZSTR_VAL(process_tags_serialized),
                .len = ZSTR_LEN(process_tags_serialized)
            }
        );
        if (result.tag != DDOG_VEC_TAG_PUSH_RESULT_OK) {
            ddog_CharSlice msg = ddog_Error_message(&result.err);
            LOG(DEBUG,
                "Failed to push process_tags tag: %.*s",
                (int) msg.len, msg.ptr);
            ddog_Error_drop(&result.err);
        }
    }

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
