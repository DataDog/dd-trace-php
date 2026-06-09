#include "auto_flush.h"

#include <ext/endpoints.h>
#include "asm_event.h"
#ifndef _WIN32
#include "comms_php.h"
#include "coms.h"
#endif
#include "configuration.h"
#include <components/log/log.h>
#include "serializer.h"
#include "span.h"
#include <ext/sidecar.h>
#include "trace_source.h"
#include "rule_matching.h"
#include "standalone_limiter.h"
#include <main/SAPI.h>
#include <components-rs/datadog.h>
#include <components-rs/sidecar.h>

ZEND_EXTERN_MODULE_GLOBALS(datadog);

ZEND_RESULT_CODE ddtrace_flush_tracer(bool force_on_startup, bool collect_cycles, bool fast_shutdown) {
    bool success = true;

    ddog_TracesBytes *traces = ddog_get_traces();
    if (collect_cycles) {
        ddtrace_serialize_closed_spans_with_cycle(traces, fast_shutdown);
    } else {
        ddtrace_serialize_closed_spans(traces, fast_shutdown);
    }

    // Prevent traces from requests not executing any PHP code:
    // PG(during_request_startup) will only be set to 0 upon execution of any PHP code.
    // e.g. php-fpm call with uri pointing to non-existing file, fpm status page, ...
    if (!force_on_startup && PG(during_request_startup)) {
        ddog_free_traces(traces);
        return SUCCESS;
    }

    if (!ddog_get_traces_size(traces)) {
        ddog_free_traces(traces);
        LOG(INFO, "No finished traces to be sent to the agent");
        return SUCCESS;
    }

    size_t limit = get_global_DD_TRACE_AGENT_MAX_PAYLOAD_SIZE();
    char *url = datadog_agent_url();

    if (get_global_DD_TRACE_SIDECAR_TRACE_SENDER()) {
        if (DATADOG_G(sidecar)) {
            ddog_SenderParameters parameters = {
                .tracer_headers_tags = {
                    .container_id = ddtrace_get_container_id(),
                    .lang = DDOG_CHARSLICE_C_BARE("php"),
                    .lang_interpreter = (ddog_CharSlice) {.ptr = sapi_module.name, .len = strlen(sapi_module.name)},
                    .lang_vendor = DDOG_CHARSLICE_C_BARE(""),
                    .tracer_version = DDOG_CHARSLICE_C_BARE(PHP_DDTRACE_VERSION),
                    .lang_version = php_version_rt,
                    .client_computed_top_level = get_DD_TRACE_STATS_COMPUTATION_ENABLED(),
                    .client_computed_stats = !get_global_DD_APM_TRACING_ENABLED() || (get_DD_TRACE_STATS_COMPUTATION_ENABLED() && ddog_agent_has_stats_computation()),
                },
                .transport = DATADOG_G(sidecar),
                .instance_id = datadog_sidecar_instance_id,
                .limit = limit,
                .n_requests = get_global_DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS(),
                .buffer_size = get_global_DD_TRACE_BUFFER_SIZE(),
                .url = (ddog_CharSlice) {.ptr = url, .len = strlen(url)},
            };
            ddog_send_traces_to_sidecar(traces, &parameters);
        } else {
            LOGEV(INFO, {
                log("Skipping flushing trace as connection to sidecar failed");
            });
        }
    } else {
#ifndef _WIN32
        success = true;
        size_t length = ddog_get_traces_size(traces);
        for (size_t i = 0; i < length; i++) {
            ddog_TraceBytes *trace = ddog_get_trace(traces, i);
            ddog_CharSlice serialized_trace = ddog_serialize_trace_into_charslice(trace);

            if (serialized_trace.len > 0) {
                if (serialized_trace.len > limit) {
                    LOG(ERROR, "Agent request payload of %zu bytes exceeds configured %zu byte limit; dropping request", serialized_trace.len, limit);
                    success = false;
                } else {
                    success = ddtrace_send_traces_via_thread(1, serialized_trace.ptr, serialized_trace.len);
                    if (success) {
                        LOGEV(INFO, {
                            log("Flushing trace of size %d to send-queue for %s", ddog_get_trace_size(trace), url);
                        });
                    }
                    dd_prepare_for_new_trace();
                }

                ddog_free_charslice(serialized_trace);
            } else {
                success = false;
            }
        }
#else
        success = false;
#endif
    }

    free(url);
    ddog_free_traces(traces);

    return success ? SUCCESS : FAILURE;
}

DATADOG_PUBLIC void ddtrace_close_all_spans_and_flush()
{
    ddtrace_close_all_open_spans(true);
    ddtrace_flush_tracer(true, true, false);
}
