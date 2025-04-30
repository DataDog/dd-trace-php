// Copyright 2024-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

#ifndef DDOG_DATA_PIPELINE_H
#define DDOG_DATA_PIPELINE_H

#include <stdarg.h>
#include <stdbool.h>
#include <stdint.h>
#include <stdlib.h>
#include "common.h"

#ifdef __cplusplus
extern "C" {
#endif // __cplusplus

/**
 * Frees `error` and all its contents. After being called error will not point to a valid memory
 * address so any further actions on it could lead to undefined behavior.
 */
void ddog_trace_exporter_error_free(struct ddog_TraceExporterError *error);

/**
 * Return a read-only pointer to the response body. This pointer is only valid as long as
 * `response` is valid.
 */
const char *ddog_trace_exporter_response_get_body(const struct ddog_TraceExporterResponse *response);

/**
 * Free `response` and all its contents. After being called response will not point to a valid
 * memory address so any further actions on it could lead to undefined behavior.
 */
void ddog_trace_exporter_response_free(struct ddog_TraceExporterResponse *response);

ddog_TracesBytes *ddog_get_traces(void);

void ddog_free_traces(ddog_TracesBytes *_traces);

uintptr_t ddog_get_traces_size(ddog_TracesBytes *traces);

ddog_TraceBytes *ddog_get_trace(ddog_TracesBytes *traces, uintptr_t index);

ddog_TraceBytes *ddog_traces_new_trace(ddog_TracesBytes *traces);

uintptr_t ddog_get_trace_size(ddog_TraceBytes *trace);

ddog_SpanBytes *ddog_get_span(ddog_TraceBytes *trace, uintptr_t index);

ddog_SpanBytes *ddog_trace_new_span(ddog_TraceBytes *trace);

ddog_CharSlice ddog_span_debug_log(const ddog_SpanBytes *span);

void ddog_free_charslice(ddog_CharSlice slice);

void ddog_set_span_service(ddog_SpanBytes *span, ddog_CharSlice slice);

ddog_CharSlice ddog_get_span_service(ddog_SpanBytes *span);

void ddog_set_span_name(ddog_SpanBytes *span, ddog_CharSlice slice);

ddog_CharSlice ddog_get_span_name(ddog_SpanBytes *span);

void ddog_set_span_resource(ddog_SpanBytes *span, ddog_CharSlice slice);

ddog_CharSlice ddog_get_span_resource(ddog_SpanBytes *span);

void ddog_set_span_type(ddog_SpanBytes *span, ddog_CharSlice slice);

ddog_CharSlice ddog_get_span_type(ddog_SpanBytes *span);

void ddog_set_span_trace_id(ddog_SpanBytes *span, uint64_t value);

uint64_t ddog_get_span_trace_id(ddog_SpanBytes *span);

void ddog_set_span_id(ddog_SpanBytes *span, uint64_t value);

uint64_t ddog_get_span_id(ddog_SpanBytes *span);

void ddog_set_span_parent_id(ddog_SpanBytes *span, uint64_t value);

uint64_t ddog_get_span_parent_id(ddog_SpanBytes *span);

void ddog_set_span_start(ddog_SpanBytes *span, int64_t value);

int64_t ddog_get_span_start(ddog_SpanBytes *span);

void ddog_set_span_duration(ddog_SpanBytes *span, int64_t value);

int64_t ddog_get_span_duration(ddog_SpanBytes *span);

void ddog_set_span_error(ddog_SpanBytes *span, int32_t value);

int32_t ddog_get_span_error(ddog_SpanBytes *span);

void ddog_add_span_meta(ddog_SpanBytes *span, ddog_CharSlice key, ddog_CharSlice value);

void ddog_del_span_meta(ddog_SpanBytes *span, ddog_CharSlice key);

ddog_CharSlice ddog_get_span_meta(ddog_SpanBytes *span, ddog_CharSlice key);

bool ddog_has_span_meta(ddog_SpanBytes *span, ddog_CharSlice key);

ddog_CharSlice *ddog_span_meta_get_keys(ddog_SpanBytes *span, uintptr_t *out_count);

void ddog_add_span_metrics(ddog_SpanBytes *span, ddog_CharSlice key, double val);

void ddog_del_span_metrics(ddog_SpanBytes *span, ddog_CharSlice key);

bool ddog_get_span_metrics(ddog_SpanBytes *span, ddog_CharSlice key, double *result);

bool ddog_has_span_metrics(ddog_SpanBytes *span, ddog_CharSlice key);

ddog_CharSlice *ddog_span_metrics_get_keys(ddog_SpanBytes *span, uintptr_t *out_count);

void ddog_add_span_meta_struct(ddog_SpanBytes *span, ddog_CharSlice key, ddog_CharSlice val);

void ddog_del_span_meta_struct(ddog_SpanBytes *span, ddog_CharSlice key);

ddog_CharSlice ddog_get_span_meta_struct(ddog_SpanBytes *span, ddog_CharSlice key);

bool ddog_has_span_meta_struct(ddog_SpanBytes *span, ddog_CharSlice key);

ddog_CharSlice *ddog_span_meta_struct_get_keys(ddog_SpanBytes *span, uintptr_t *out_count);

void ddog_span_free_keys_ptr(ddog_CharSlice *keys_ptr, uintptr_t count);

ddog_SpanLinkBytes *ddog_span_new_link(ddog_SpanBytes *span);

void ddog_set_link_tracestate(ddog_SpanLinkBytes *link, ddog_CharSlice slice);

void ddog_set_link_trace_id(ddog_SpanLinkBytes *link, uint64_t value);

void ddog_set_link_trace_id_high(ddog_SpanLinkBytes *link, uint64_t value);

void ddog_set_link_span_id(ddog_SpanLinkBytes *link, uint64_t value);

void ddog_set_link_flags(ddog_SpanLinkBytes *link, uint64_t value);

void ddog_add_link_attributes(ddog_SpanLinkBytes *link, ddog_CharSlice key, ddog_CharSlice val);

ddog_SpanEventBytes *ddog_span_new_event(ddog_SpanBytes *span);

void ddog_set_event_name(ddog_SpanEventBytes *event, ddog_CharSlice slice);

void ddog_set_event_time(ddog_SpanEventBytes *event, uint64_t val);

void ddog_add_event_attributes_str(ddog_SpanEventBytes *event,
                                   ddog_CharSlice key,
                                   ddog_CharSlice val);

void ddog_add_event_attributes_bool(ddog_SpanEventBytes *event, ddog_CharSlice key, bool val);

void ddog_add_event_attributes_int(ddog_SpanEventBytes *event, ddog_CharSlice key, int64_t val);

void ddog_add_event_attributes_float(ddog_SpanEventBytes *event, ddog_CharSlice key, double val);

ddog_CharSlice ddog_serialize_trace_into_c_string(ddog_TraceBytes *trace);

void ddog_trace_exporter_config_new(struct ddog_TraceExporterConfig **out_handle);

/**
 * Frees TraceExporterConfig handle internal resources.
 */
void ddog_trace_exporter_config_free(struct ddog_TraceExporterConfig *handle);

/**
 * Sets traces destination.
 */
struct ddog_TraceExporterError *ddog_trace_exporter_config_set_url(struct ddog_TraceExporterConfig *config,
                                                                   ddog_CharSlice url);

/**
 * Sets tracer's version to be included in the headers request.
 */
struct ddog_TraceExporterError *ddog_trace_exporter_config_set_tracer_version(struct ddog_TraceExporterConfig *config,
                                                                              ddog_CharSlice version);

/**
 * Sets tracer's language to be included in the headers request.
 */
struct ddog_TraceExporterError *ddog_trace_exporter_config_set_language(struct ddog_TraceExporterConfig *config,
                                                                        ddog_CharSlice lang);

/**
 * Sets tracer's language version to be included in the headers request.
 */
struct ddog_TraceExporterError *ddog_trace_exporter_config_set_lang_version(struct ddog_TraceExporterConfig *config,
                                                                            ddog_CharSlice version);

/**
 * Sets tracer's language interpreter to be included in the headers request.
 */
struct ddog_TraceExporterError *ddog_trace_exporter_config_set_lang_interpreter(struct ddog_TraceExporterConfig *config,
                                                                                ddog_CharSlice interpreter);

/**
 * Sets hostname information to be included in the headers request.
 */
struct ddog_TraceExporterError *ddog_trace_exporter_config_set_hostname(struct ddog_TraceExporterConfig *config,
                                                                        ddog_CharSlice hostname);

/**
 * Sets environmet information to be included in the headers request.
 */
struct ddog_TraceExporterError *ddog_trace_exporter_config_set_env(struct ddog_TraceExporterConfig *config,
                                                                   ddog_CharSlice env);

struct ddog_TraceExporterError *ddog_trace_exporter_config_set_version(struct ddog_TraceExporterConfig *config,
                                                                       ddog_CharSlice version);

/**
 * Sets service name to be included in the headers request.
 */
struct ddog_TraceExporterError *ddog_trace_exporter_config_set_service(struct ddog_TraceExporterConfig *config,
                                                                       ddog_CharSlice service);

/**
 * Enables metrics.
 */
struct ddog_TraceExporterError *ddog_trace_exporter_config_enable_telemetry(struct ddog_TraceExporterConfig *config,
                                                                            const struct ddog_TelemetryClientConfig *telemetry_cfg);

/**
 * Set client-side stats computation status.
 */
struct ddog_TraceExporterError *ddog_trace_exporter_config_set_compute_stats(struct ddog_TraceExporterConfig *config,
                                                                             bool is_enabled);

/**
 * Sets the `X-Datadog-Test-Session-Token` header. Only used for testing with the test agent.
 */
struct ddog_TraceExporterError *ddog_trace_exporter_config_set_test_session_token(struct ddog_TraceExporterConfig *config,
                                                                                  ddog_CharSlice token);

/**
 * Create a new TraceExporter instance.
 *
 * # Arguments
 *
 * * `out_handle` - The handle to write the TraceExporter instance in.
 * * `config` - The configuration used to set up the TraceExporter handle.
 */
struct ddog_TraceExporterError *ddog_trace_exporter_new(struct ddog_TraceExporter **out_handle,
                                                        const struct ddog_TraceExporterConfig *config);

/**
 * Free the TraceExporter instance.
 *
 * # Arguments
 *
 * * handle - The handle to the TraceExporter instance.
 */
void ddog_trace_exporter_free(struct ddog_TraceExporter *handle);

/**
 * Send traces to the Datadog Agent.
 *
 * # Arguments
 *
 * * `handle` - The handle to the TraceExporter instance.
 * * `trace` - The traces to send to the Datadog Agent in the input format used to create the
 *   TraceExporter. The memory for the trace must be valid for the life of the call to this
 *   function.
 * * `trace_count` - The number of traces to send to the Datadog Agent.
 * * `response_out` - Optional handle to store a pointer to the agent response information.
 */
struct ddog_TraceExporterError *ddog_trace_exporter_send(const struct ddog_TraceExporter *handle,
                                                         ddog_ByteSlice trace,
                                                         uintptr_t trace_count,
                                                         struct ddog_TraceExporterResponse **response_out);

#ifdef __cplusplus
}  // extern "C"
#endif  // __cplusplus

#endif  /* DDOG_DATA_PIPELINE_H */
