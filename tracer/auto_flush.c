#include "auto_flush.h"

#include <ext/endpoints.h>
#include "asm_event.h"
#ifndef _WIN32
#include "comms_php.h"
#include "coms.h"
#endif
#include "configuration.h"
#include <ext/agent_info.h>
#include <ext/ffi_utils.h>
#include <ext/process_tags.h>
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

    // Serialization builds the native V1 payload directly into the builder (no v0.4 intermediate).
    // The sidecar consumes it and negotiates V1-vs-v0.4 with the agent; the in-process (<=8.2)
    // sender downgrades it to v0.4 bytes at flush time.
    ddtrace_v1_ctx v1_ctx = {.builder = ddog_v1_new_builder(), .chunk = DD_V1_CHUNK_NONE};
    ddtrace_v1_ctx *v1 = &v1_ctx;

    ddog_TracesBytes *traces = ddog_get_traces();
    if (collect_cycles) {
        ddtrace_serialize_closed_spans_with_cycle(traces, v1, fast_shutdown);
    } else {
        ddtrace_serialize_closed_spans(traces, v1, fast_shutdown);
    }

    // Prevent traces from requests not executing any PHP code:
    // PG(during_request_startup) will only be set to 0 upon execution of any PHP code.
    // e.g. php-fpm call with uri pointing to non-existing file, fpm status page, ...
    if (!force_on_startup && PG(during_request_startup)) {
        ddog_v1_free_builder(v1->builder);
        ddog_free_traces(traces);
        return SUCCESS;
    }

    // Spans are built into the builder, not the (empty) V0.4 traces, so gate on the chunk count.
    size_t payload_count = ddog_v1_get_chunk_count(v1->builder);
    if (!payload_count) {
        ddog_v1_free_builder(v1->builder);
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
            // The sidecar receives the native V1 payload and negotiates/downgrades with the agent.
            // lang/tracer_version/container_id come from parameters.tracer_headers_tags in the FFI;
            // process tags travel as the span meta "_dd.tags.process".
            uint8_t formatted_runtime_id[36];
            datadog_format_runtime_id(&formatted_runtime_id);
            ddog_TracerMetadataV1 metadata = {
                .hostname = dd_zend_string_to_CharSlice(get_DD_HOSTNAME()),
                .env = dd_zend_string_to_CharSlice(get_DD_ENV()),
                .app_version = dd_zend_string_to_CharSlice(get_DD_VERSION()),
                .runtime_id = (ddog_CharSlice) {.ptr = (char *) formatted_runtime_id, .len = sizeof(formatted_runtime_id)},
                .git_commit_sha = dd_zend_string_to_CharSlice(get_DD_GIT_COMMIT_SHA()),
            };
            ddog_send_traces_to_sidecar_v1(v1->builder, &parameters, &metadata);  // consumes the builder
        } else {
            ddog_v1_free_builder(v1->builder);  // not handed to any FFI on this path
            LOGEV(INFO, {
                log("Skipping flushing trace as connection to sidecar failed");
            });
        }
    } else {
#ifndef _WIN32
        // Removable v0.4 bolt-on for the in-process (<=8.2) sender: it downgrades the V1 builder to
        // the v0.4 collection and sends each trace to /v0.4/traces. The background sender's
        // array-of-1 framing (comms_php.c mpack_expect_array_match) can't parse a native V1 payload
        // (a single msgpack MAP), so in-process never uses /v1.0/traces. Deleting this bolt-on +
        // the endpoint pin reverts to V1-only.
        ddtrace_coms_set_v1_traces_endpoint(false);
        // Downgrade consumes v1->builder and returns the decoded v0.4 collection; free it below.
        ddog_TracesBytes *v04_traces = ddog_downgrade_v1_builder_to_v04_traces(v1->builder);
        size_t trace_count = ddog_get_traces_size(v04_traces);
        for (size_t i = 0; i < trace_count; i++) {
            // One msgpack array-of-1 per trace, matching the background sender's framing.
            ddog_CharSlice payload = ddog_serialize_trace_into_charslice(ddog_get_trace(v04_traces, i));
            if (payload.len > 0 && payload.len <= limit) {
                if (!ddtrace_send_traces_via_thread(1, payload.ptr, payload.len)) {
                    success = false;
                }
            } else {
                if (payload.len > limit) {
                    LOG(ERROR, "Agent request payload of %zu bytes exceeds configured %zu byte limit; dropping request", payload.len, limit);
                }
                success = false;
            }
            ddog_free_charslice(payload);
        }
        if (success) {
            LOGEV(INFO, {
                log("Flushing %zu v0.4 trace(s) to send-queue for %s", trace_count, url);
            });
        }
        dd_prepare_for_new_trace();
        ddog_free_traces(v04_traces);
#else
        ddog_v1_free_builder(v1->builder);  // in-process sender unavailable on Windows; not consumed
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
