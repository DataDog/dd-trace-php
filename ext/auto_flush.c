#include "auto_flush.h"

#include "comms_php.h"
#include "coms.h"
#include "ddtrace_string.h"
#include "logging.h"
#include "serializer.h"
#include "span.h"

ZEND_RESULT_CODE ddtrace_flush_tracer(bool force_on_startup) {
    bool success = true;

    zval trace, traces;
    ddtrace_serialize_closed_spans(&trace);


    // Prevent traces from requests not executing any PHP code:
    // PG(during_request_startup) will only be set to 0 upon execution of any PHP code.
    // e.g. php-fpm call with uri pointing to non-existing file, fpm status page, ...
    if (!force_on_startup && PG(during_request_startup)) {
        zend_array_destroy(Z_ARR(trace));
        return SUCCESS;
    }

    if (zend_hash_num_elements(Z_ARR(trace)) == 0) {
        zend_array_destroy(Z_ARR(trace));
        ddtrace_log_debug("No finished traces to be sent to the agent");
        return SUCCESS;
    }

    // background sender only wants a singular trace
    array_init(&traces);
    zend_hash_index_add(Z_ARR(traces), 0, &trace);

    char *payload;
    size_t size, limit = get_global_DD_TRACE_AGENT_MAX_PAYLOAD_SIZE();
    if (ddtrace_serialize_simple_array_into_c_string(&traces, &payload, &size)) {
        if (size > limit) {
            ddtrace_log_errf("Agent request payload of %zu bytes exceeds configured %zu byte limit; dropping request", size, limit);
            success = false;
        } else {
            success = ddtrace_send_traces_via_thread(1, payload, size);
            if (success) {
                char *url = ddtrace_agent_url();
                ddtrace_log_debugf("Flushing trace of size %d to send-queue for %s",
                                   zend_hash_num_elements(Z_ARR(trace)), url);
                free(url);
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


DDTRACE_PUBLIC void ddtrace_close_all_spans_and_flush()
{
    ddtrace_close_all_open_spans(true);
    ddtrace_flush_tracer(true);
}
