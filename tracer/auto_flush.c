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

    // The sidecar sender always emits the native V1 wire (the sidecar negotiates V1-vs-V0.4 with the
    // agent and downgrades if needed). On this path serialization builds the native V1 payload
    // directly into the builder (no V0.4 intermediate); the in-process (<=8.2) path builds V0.4.
    bool use_sidecar = get_global_DD_TRACE_SIDECAR_TRACE_SENDER() && DATADOG_G(sidecar);
    ddtrace_v1_ctx v1_ctx = {.builder = NULL, .chunk = DD_V1_CHUNK_NONE};
    ddtrace_v1_ctx *v1 = NULL;
    if (use_sidecar) {
        v1_ctx.builder = ddog_v1_new_builder();
        v1 = &v1_ctx;
    }

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
        if (v1) ddog_v1_free_builder(v1->builder);
        ddog_free_traces(traces);
        return SUCCESS;
    }

    // On the V1 path spans are built into the builder, not the (empty) V0.4 traces, so gate on the
    // builder's chunk count instead.
    size_t payload_count = v1 ? ddog_v1_get_chunk_count(v1->builder) : ddog_get_traces_size(traces);
    if (!payload_count) {
        if (v1) ddog_v1_free_builder(v1->builder);
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
            // The sidecar always receives the native V1 payload and negotiates/downgrades with the
            // agent. Shrunk V1 metadata: lang/lang_version/tracer_version/container_id are sourced
            // from parameters.tracer_headers_tags inside the FFI; process tags travel as the span
            // meta "_dd.tags.process" emitted during serialization.
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
            LOGEV(INFO, {
                log("Skipping flushing trace as connection to sidecar failed");
            });
        }
    } else {
#ifndef _WIN32
        // Removable v0.4->v1 bolt-on: the in-process (<=8.2) sender builds V0.4 (above); if the agent
        // advertises /v1.0/traces we transcode the already-built V0.4 collection into a single native
        // V1 payload and POST it to /v1.0/traces, otherwise we POST the V0.4 bytes to /v0.4/traces.
        // Deleting this whole `if (send_v1)` branch + the endpoint flag reverts to V0.4-only.
        bool send_v1 = ddtrace_agent_supports_v1_traces();
        ddtrace_coms_set_v1_traces_endpoint(send_v1);
        if (send_v1) {
            uint8_t formatted_runtime_id[36];
            datadog_format_runtime_id(&formatted_runtime_id);
            ddog_TracerMetadataV1 metadata = {
                .hostname = dd_zend_string_to_CharSlice(get_DD_HOSTNAME()),
                .env = dd_zend_string_to_CharSlice(get_DD_ENV()),
                .app_version = dd_zend_string_to_CharSlice(get_DD_VERSION()),
                .runtime_id = (ddog_CharSlice) {.ptr = (char *) formatted_runtime_id, .len = sizeof(formatted_runtime_id)},
                .git_commit_sha = dd_zend_string_to_CharSlice(get_DD_GIT_COMMIT_SHA()),
            };
            ddog_CharSlice container_id = ddtrace_get_container_id();
            ddog_CharSlice payload = ddog_serialize_trace_v04_as_v1_into_charslice(
                traces, &metadata, container_id, DDOG_CHARSLICE_C("php"), php_version_rt,
                DDOG_CHARSLICE_C(PHP_DDTRACE_VERSION));
            if (payload.len > 0 && payload.len <= limit) {
                success = ddtrace_send_traces_via_thread(ddog_get_traces_size(traces), payload.ptr, payload.len);
                if (success) {
                    LOGEV(INFO, {
                        log("Flushing V1 payload of %zu bytes to send-queue for %s/v1.0/traces", payload.len, url);
                    });
                }
                dd_prepare_for_new_trace();
            } else {
                if (payload.len > limit) {
                    LOG(ERROR, "Agent request payload of %zu bytes exceeds configured %zu byte limit; dropping request", payload.len, limit);
                }
                success = false;
            }
            ddog_free_charslice(payload);
        } else {
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
