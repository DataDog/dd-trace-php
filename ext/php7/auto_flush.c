#include "auto_flush.h"

#include "comms_php.h"
#include "ddtrace_string.h"
#include "logging.h"
#include "serializer.h"
#include "span.h"

ZEND_RESULT_CODE ddtrace_flush_tracer() {
    bool success = true;

    zval trace, traces;
    ddtrace_serialize_closed_spans(&trace);

    if (zend_hash_num_elements(Z_ARR(trace)) == 0) {
        zend_array_destroy(Z_ARR(trace));
        ddtrace_log_debug("No finished traces to be sent to the agent");
        return SUCCESS;
    }

    // background sender only wants a singular trace
    array_init(&traces);
    zend_hash_index_add(Z_ARR(traces), 0, &trace);

    char *payload;
    size_t size;
    if (ddtrace_serialize_simple_array_into_c_string(&traces, &payload, &size)) {
        // The 10MB payload cap is inclusive, thus we use >, not >=
        // https://github.com/DataDog/datadog-agent/blob/355a34d610bd1554572d7733454ac4af3acd89cd/pkg/trace/api/limited_reader.go#L37
        if (size > AGENT_REQUEST_BODY_LIMIT) {
            ddtrace_log_errf("Agent request payload of %zu bytes exceeds 10MB limit; dropping request", size);
            success = false;
        } else {
            success = ddtrace_send_traces_via_thread(1, payload, size);
            if (success) {
                ddtrace_log_debugf("Successfully triggered flush with trace of size %d",
                                   zend_hash_num_elements(Z_ARR(trace)));
            }
            dd_prepare_for_new_trace();
        }

        free(payload);
    } else {
        success = false;
    }

    zval_ptr_dtor(&traces);

    return success ? SUCCESS : FAILURE;
}
