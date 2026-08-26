// Copyright 2021-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

#ifndef DDOG_SIDECAR_H
#define DDOG_SIDECAR_H

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include <stdio.h>
#include "common.h"

#if defined(_WIN32)
bool ddog_setup_crashtracking(const struct ddog_Endpoint *endpoint, ddog_crasht_Metadata metadata);
#endif

/**
 * This creates Rust PlatformHandle<File> from supplied C std FILE object.
 * This method takes the ownership of the underlying file descriptor.
 *
 * # Safety
 * Caller must ensure the file descriptor associated with FILE pointer is open, and valid
 * Caller must not close the FILE associated file descriptor after calling this function
 */
struct ddog_NativeFile ddog_ph_file_from(FILE *file);

struct ddog_NativeFile *ddog_ph_file_clone(const struct ddog_NativeFile *platform_handle);

void ddog_ph_file_drop(struct ddog_NativeFile ph);

ddog_MaybeError ddog_alloc_anon_shm_handle(uintptr_t size, struct ddog_ShmHandle **handle);

ddog_MaybeError ddog_alloc_anon_shm_handle_named(uintptr_t size,
                                                 struct ddog_ShmHandle **handle,
                                                 ddog_CharSlice name);

ddog_MaybeError ddog_map_shm(struct ddog_ShmHandle *handle,
                             struct ddog_MappedMem_ShmHandle **mapped,
                             void **pointer,
                             uintptr_t *size);

struct ddog_ShmHandle *ddog_unmap_shm(struct ddog_MappedMem_ShmHandle *mapped);

void ddog_drop_anon_shm_handle(struct ddog_ShmHandle*);

ddog_MaybeError ddog_create_agent_remote_config_writer(struct ddog_AgentRemoteConfigWriter_ShmHandle **writer,
                                                       struct ddog_ShmHandle **handle);

struct ddog_AgentRemoteConfigReader *ddog_agent_remote_config_reader_for_endpoint(const struct ddog_Endpoint *endpoint);

ddog_MaybeError ddog_agent_remote_config_reader_for_anon_shm(const struct ddog_ShmHandle *handle,
                                                             struct ddog_AgentRemoteConfigReader **reader);

void ddog_agent_remote_config_write(const struct ddog_AgentRemoteConfigWriter_ShmHandle *writer,
                                    ddog_CharSlice data);

bool ddog_agent_remote_config_read(struct ddog_AgentRemoteConfigReader *reader,
                                   ddog_CharSlice *data);

void ddog_agent_remote_config_reader_drop(struct ddog_AgentRemoteConfigReader*);

void ddog_agent_remote_config_writer_drop(struct ddog_AgentRemoteConfigWriter_ShmHandle*);

struct ddog_RemoteConfigReader *ddog_remote_config_reader_for_endpoint(const ddog_CharSlice *language,
                                                                       const ddog_CharSlice *tracer_version,
                                                                       const struct ddog_Endpoint *endpoint,
                                                                       ddog_CharSlice service_name,
                                                                       ddog_CharSlice env_name,
                                                                       ddog_CharSlice app_version,
                                                                       const struct ddog_Vec_Tag *tags);

/**
 * # Safety
 * Argument should point to a valid C string.
 */
struct ddog_RemoteConfigReader *ddog_remote_config_reader_for_path(const char *path);

char *ddog_remote_config_path(const struct ddog_ConfigInvariants *id,
                              const struct ddog_Arc_Target *target);

void ddog_remote_config_path_free(char *path);

bool ddog_remote_config_read(struct ddog_RemoteConfigReader *reader, ddog_CharSlice *data);

void ddog_remote_config_reader_drop(struct ddog_RemoteConfigReader*);

void ddog_sidecar_transport_drop(struct ddog_SidecarTransport*);

/**
 * # Safety
 * Caller must ensure the process is safe to fork, at the time when this method is called
 */
ddog_MaybeError ddog_sidecar_connect(struct ddog_SidecarTransport **connection);

ddog_MaybeError ddog_sidecar_connect_master(int32_t pid);

ddog_MaybeError ddog_sidecar_connect_worker(int32_t pid, struct ddog_SidecarTransport **connection);

ddog_MaybeError ddog_sidecar_shutdown_master_listener(void);

bool ddog_sidecar_is_master_listener_active(int32_t pid);

ddog_MaybeError ddog_sidecar_clear_inherited_listener(void);

ddog_MaybeError ddog_sidecar_ping(struct ddog_SidecarTransport **transport);

ddog_MaybeError ddog_sidecar_flush(struct ddog_SidecarTransport **transport,
                                   struct ddog_SidecarFlushOptions options);

struct ddog_InstanceId *ddog_sidecar_instanceId_build(ddog_CharSlice session_id,
                                                      ddog_CharSlice runtime_id);

void ddog_sidecar_instanceId_drop(struct ddog_InstanceId *instance_id);

ddog_QueueId ddog_sidecar_queueId_generate(void);

struct ddog_RuntimeMetadata *ddog_sidecar_runtimeMeta_build(ddog_CharSlice language_name,
                                                            ddog_CharSlice language_version,
                                                            ddog_CharSlice tracer_version);

void ddog_sidecar_runtimeMeta_drop(struct ddog_RuntimeMetadata *meta);

/**
 * Reports the runtime configuration to the telemetry.
 */
ddog_MaybeError ddog_sidecar_telemetry_enqueueConfig(struct ddog_SidecarTransport **transport,
                                                     const struct ddog_InstanceId *instance_id,
                                                     const ddog_QueueId *queue_id,
                                                     ddog_CharSlice config_key,
                                                     ddog_CharSlice config_value,
                                                     enum ddog_ConfigurationOrigin origin,
                                                     ddog_CharSlice config_id,
                                                     struct ddog_Option_U64 seq_id);

/**
 * Reports an endpoint to the telemetry.
 */
ddog_MaybeError ddog_sidecar_telemetry_addEndpoint(struct ddog_SidecarTransport **transport,
                                                   const struct ddog_InstanceId *instance_id,
                                                   const ddog_QueueId *queue_id,
                                                   enum ddog_Method method,
                                                   ddog_CharSlice path,
                                                   ddog_CharSlice operation_name,
                                                   ddog_CharSlice resource_name);

/**
 * Reports a dependency to the telemetry.
 */
ddog_MaybeError ddog_sidecar_telemetry_addDependency(struct ddog_SidecarTransport **transport,
                                                     const struct ddog_InstanceId *instance_id,
                                                     const ddog_QueueId *queue_id,
                                                     ddog_CharSlice dependency_name,
                                                     ddog_CharSlice dependency_version);

/**
 * Reports an integration to the telemetry.
 */
ddog_MaybeError ddog_sidecar_telemetry_addIntegration(struct ddog_SidecarTransport **transport,
                                                      const struct ddog_InstanceId *instance_id,
                                                      const ddog_QueueId *queue_id,
                                                      ddog_CharSlice integration_name,
                                                      ddog_CharSlice integration_version,
                                                      bool integration_enabled);

/**
 * Enqueues a list of actions to be performed.
 */
ddog_MaybeError ddog_sidecar_lifecycle_end(struct ddog_SidecarTransport **transport,
                                           const struct ddog_InstanceId *instance_id,
                                           const ddog_QueueId *queue_id);

/**
 * Enqueues a list of actions to be performed.
 */
ddog_MaybeError ddog_sidecar_application_remove(struct ddog_SidecarTransport **transport,
                                                const struct ddog_InstanceId *instance_id,
                                                const ddog_QueueId *queue_id);

/**
 * Flushes the telemetry data.
 */
ddog_MaybeError ddog_sidecar_telemetry_flush(struct ddog_SidecarTransport **transport,
                                             const struct ddog_InstanceId *instance_id,
                                             const ddog_QueueId *queue_id);

/**
 * Returns whether the sidecar transport is closed or not.
 */
bool ddog_sidecar_is_closed(struct ddog_SidecarTransport **transport);

/**
 * Sets the configuration for a session.
 */
ddog_MaybeError ddog_sidecar_session_set_config(struct ddog_SidecarTransport **transport,
                                                ddog_CharSlice session_id,
                                                const struct ddog_Endpoint *agent_endpoint,
                                                const struct ddog_Endpoint *dogstatsd_endpoint,
                                                const struct ddog_Endpoint *otlp_metrics_endpoint,
                                                ddog_CharSlice language,
                                                ddog_CharSlice language_version,
                                                ddog_CharSlice tracer_version,
                                                uint32_t flush_interval_milliseconds,
                                                uint32_t retry_interval_milliseconds,
                                                uint32_t remote_config_poll_interval_millis,
                                                uint32_t telemetry_heartbeat_interval_millis,
                                                uint64_t telemetry_extended_heartbeat_interval_millis,
                                                uintptr_t force_flush_size,
                                                uintptr_t force_drop_size,
                                                ddog_CharSlice log_level,
                                                ddog_CharSlice log_path,
                                                void *_remote_config_notify_function,
                                                const enum ddog_RemoteConfigProduct *remote_config_products,
                                                uintptr_t remote_config_products_count,
                                                const enum ddog_RemoteConfigCapabilities *remote_config_capabilities,
                                                uintptr_t remote_config_capabilities_count,
                                                bool remote_config_enabled,
                                                bool is_fork,
                                                const struct ddog_Vec_Tag *process_tags,
                                                ddog_CharSlice hostname,
                                                ddog_CharSlice root_service,
                                                ddog_CharSlice root_session_id,
                                                ddog_CharSlice parent_session_id);

/**
 * Updates the process_tags for an existing session.
 */
ddog_MaybeError ddog_sidecar_session_set_process_tags(struct ddog_SidecarTransport **transport,
                                                      const struct ddog_Vec_Tag *process_tags);

/**
 * Records the tracer's auto-resolved default service name for the session
 * (process-bound; sidecar emits `svc.auto:<name>` when `DD_SERVICE` is not
 * currently set for the active request). Pass an empty `CharSlice` to clear.
 */
ddog_MaybeError ddog_sidecar_session_set_default_service_name(struct ddog_SidecarTransport **transport,
                                                              ddog_CharSlice default_service_name);

/**
 * Records whether `DD_SERVICE` is currently set for the session (per-request
 * mutable; refresh on each RINIT). When `true` the sidecar emits
 * `svc.user:true`; when `false` it falls back to the previously-recorded
 * `svc.auto:<name>` (if any).
 */
ddog_MaybeError ddog_sidecar_session_set_user_service_defined(struct ddog_SidecarTransport **transport,
                                                              bool is_user_defined);

/**
 * Enqueues a telemetry log action to be processed internally.
 * Non-blocking. Logs might be dropped if the internal queue is full.
 *
 * # Safety
 * Pointers must be valid, strings must be null-terminated if not null.
 */
ddog_MaybeError ddog_sidecar_enqueue_telemetry_log(ddog_CharSlice session_id_ffi,
                                                   ddog_CharSlice runtime_id_ffi,
                                                   ddog_CharSlice service_name_ffi,
                                                   ddog_CharSlice env_name_ffi,
                                                   ddog_CharSlice identifier_ffi,
                                                   enum ddog_LogLevel level,
                                                   ddog_CharSlice message_ffi,
                                                   ddog_CharSlice *stack_trace_ffi,
                                                   ddog_CharSlice *tags_ffi,
                                                   bool is_sensitive);

/**
 * Enqueues a telemetry point to be processed internally.
 *
 * # Safety
 * Pointers must be valid, strings must be null-terminated if not null.
 */
ddog_MaybeError ddog_sidecar_enqueue_telemetry_point(ddog_CharSlice session_id_ffi,
                                                     ddog_CharSlice runtime_id_ffi,
                                                     ddog_CharSlice service_name_ffi,
                                                     ddog_CharSlice env_name_ffi,
                                                     ddog_CharSlice metric_name_ffi,
                                                     double value,
                                                     ddog_CharSlice *tags_ffi);

/**
 * Registers a telemetry metric to be processed internally.
 *
 * # Safety
 * Pointers must be valid, strings must be null-terminated if not null.
 */
ddog_MaybeError ddog_sidecar_enqueue_telemetry_metric(ddog_CharSlice session_id_ffi,
                                                      ddog_CharSlice runtime_id_ffi,
                                                      ddog_CharSlice service_name_ffi,
                                                      ddog_CharSlice env_name_ffi,
                                                      ddog_CharSlice metric_name_ffi,
                                                      enum ddog_MetricType metric_type,
                                                      enum ddog_MetricNamespace metric_namespace);

/**
 * Sends a trace to the sidecar via shared memory.
 */
ddog_MaybeError ddog_sidecar_send_trace_v04_shm(struct ddog_SidecarTransport **transport,
                                                const struct ddog_InstanceId *instance_id,
                                                struct ddog_ShmHandle *shm_handle,
                                                uintptr_t len,
                                                const struct ddog_TracerHeaderTags *tracer_header_tags);

/**
 * Sends a trace as bytes to the sidecar.
 */
ddog_MaybeError ddog_sidecar_send_trace_v04_bytes(struct ddog_SidecarTransport **transport,
                                                  const struct ddog_InstanceId *instance_id,
                                                  ddog_CharSlice data,
                                                  const struct ddog_TracerHeaderTags *tracer_header_tags);

/**
 * Sends a V1-encoded trace to the sidecar via shared memory. The sidecar decodes the V1
 * `TracerPayload`, can inspect it, and re-encodes it as V1 msgpack on the way to the agent's
 * `/v1.0/traces` endpoint.
 */
ddog_MaybeError ddog_sidecar_send_trace_v1_shm(struct ddog_SidecarTransport **transport,
                                               const struct ddog_InstanceId *instance_id,
                                               struct ddog_ShmHandle *shm_handle,
                                               uintptr_t len,
                                               const struct ddog_TracerHeaderTags *tracer_header_tags);

/**
 * Sends a V1-encoded trace as bytes to the sidecar. The sidecar decodes the V1 `TracerPayload`,
 * can inspect it, and re-encodes it as V1 msgpack on the way to the agent's `/v1.0/traces`
 * endpoint.
 */
ddog_MaybeError ddog_sidecar_send_trace_v1_bytes(struct ddog_SidecarTransport **transport,
                                                 const struct ddog_InstanceId *instance_id,
                                                 ddog_CharSlice data,
                                                 const struct ddog_TracerHeaderTags *tracer_header_tags);

ddog_MaybeError ddog_sidecar_send_debugger_data(struct ddog_SidecarTransport **transport,
                                                const struct ddog_InstanceId *instance_id,
                                                ddog_QueueId queue_id,
                                                struct ddog_Vec_DebuggerPayload payloads);

ddog_MaybeError ddog_sidecar_send_debugger_datum(struct ddog_SidecarTransport **transport,
                                                 const struct ddog_InstanceId *instance_id,
                                                 ddog_QueueId queue_id,
                                                 struct ddog_DebuggerPayload *payload);

/**
 * Send structured FFE exposure events to the sidecar. The sidecar owns
 * deduplication, JSON serialization, and Agent EVP delivery. This function is
 * caller-driven; shared libdatadog evaluator calls do not log unless an SDK
 * explicitly sends this action.
 *
 * # Safety
 * `context` and every element in `exposures` must contain valid UTF-8
 * `CharSlice` values. Empty `exposures` is a no-op.
 */
ddog_MaybeError ddog_sidecar_send_ffe_exposure_batch(struct ddog_SidecarTransport **transport,
                                                     const struct ddog_InstanceId *instance_id,
                                                     const ddog_QueueId *queue_id,
                                                     const struct ddog_FfeTelemetryContext *context,
                                                     struct ddog_Slice_FfeExposure exposures);

/**
 * Send structured FFE flag evaluation events to the sidecar. The sidecar owns
 * JSON serialization and Agent EVP delivery. This function is caller-driven;
 * callers must aggregate and bound event cardinality before passing a batch.
 *
 * # Safety
 * `context` and every element in `flag_evaluations` must contain valid UTF-8
 * `CharSlice` values. Empty `flag_evaluations` is a no-op.
 */
ddog_MaybeError ddog_sidecar_send_ffe_flag_evaluation_batch(struct ddog_SidecarTransport **transport,
                                                            const struct ddog_InstanceId *instance_id,
                                                            const ddog_QueueId *queue_id,
                                                            const struct ddog_FfeTelemetryContext *context,
                                                            struct ddog_Slice_FfeFlagEvaluation flag_evaluations);

/**
 * Send structured FFE evaluation metric events to the sidecar. The sidecar
 * owns aggregation, OTLP/protobuf serialization, and OTLP HTTP delivery. This
 * function is caller-driven so SDKs with existing host-language hooks can
 * safely coexist until they explicitly migrate.
 *
 * # Safety
 * `context` and every element in `metrics` must contain valid UTF-8
 * `CharSlice` values. Empty `metrics` is a no-op.
 */
ddog_MaybeError ddog_sidecar_send_ffe_evaluation_metrics(struct ddog_SidecarTransport **transport,
                                                         const struct ddog_InstanceId *instance_id,
                                                         const ddog_QueueId *queue_id,
                                                         const struct ddog_FfeTelemetryContext *context,
                                                         struct ddog_Slice_FfeEvaluationMetric metrics);

ddog_MaybeError ddog_sidecar_send_debugger_diagnostics(struct ddog_SidecarTransport **transport,
                                                       const struct ddog_InstanceId *instance_id,
                                                       ddog_QueueId queue_id,
                                                       struct ddog_DebuggerPayload diagnostics_payload);

ddog_MaybeError ddog_sidecar_set_universal_service_tags(struct ddog_SidecarTransport **transport,
                                                        const struct ddog_InstanceId *instance_id,
                                                        const ddog_QueueId *queue_id,
                                                        ddog_CharSlice service_name,
                                                        ddog_CharSlice env_name,
                                                        ddog_CharSlice app_version,
                                                        const struct ddog_Vec_Tag *global_tags,
                                                        enum ddog_DynamicInstrumentationConfigState dynamic_instrumentation_state,
                                                        uint64_t remote_config_generation);

ddog_MaybeError ddog_sidecar_set_request_config(struct ddog_SidecarTransport **transport,
                                                const struct ddog_InstanceId *instance_id,
                                                const ddog_QueueId *queue_id,
                                                enum ddog_DynamicInstrumentationConfigState dynamic_instrumentation_state);

/**
 * Dumps the current state of the sidecar.
 */
ddog_CharSlice ddog_sidecar_dump(struct ddog_SidecarTransport **transport);

/**
 * Retrieves the current statistics of the sidecar.
 */
ddog_CharSlice ddog_sidecar_stats(struct ddog_SidecarTransport **transport);

/**
 * Send a DogStatsD "count" metric.
 */
ddog_MaybeError ddog_sidecar_dogstatsd_count(struct ddog_SidecarTransport **transport,
                                             const struct ddog_InstanceId *instance_id,
                                             ddog_CharSlice metric,
                                             int64_t value,
                                             const struct ddog_Vec_Tag *tags);

/**
 * Send a DogStatsD "distribution" metric.
 */
ddog_MaybeError ddog_sidecar_dogstatsd_distribution(struct ddog_SidecarTransport **transport,
                                                    const struct ddog_InstanceId *instance_id,
                                                    ddog_CharSlice metric,
                                                    double value,
                                                    const struct ddog_Vec_Tag *tags);

/**
 * Send a DogStatsD "gauge" metric.
 */
ddog_MaybeError ddog_sidecar_dogstatsd_gauge(struct ddog_SidecarTransport **transport,
                                             const struct ddog_InstanceId *instance_id,
                                             ddog_CharSlice metric,
                                             double value,
                                             const struct ddog_Vec_Tag *tags);

/**
 * Send a DogStatsD "histogram" metric.
 */
ddog_MaybeError ddog_sidecar_dogstatsd_histogram(struct ddog_SidecarTransport **transport,
                                                 const struct ddog_InstanceId *instance_id,
                                                 ddog_CharSlice metric,
                                                 double value,
                                                 const struct ddog_Vec_Tag *tags);

/**
 * Send a DogStatsD "set" metric.
 */
ddog_MaybeError ddog_sidecar_dogstatsd_set(struct ddog_SidecarTransport **transport,
                                           const struct ddog_InstanceId *instance_id,
                                           ddog_CharSlice metric,
                                           int64_t value,
                                           const struct ddog_Vec_Tag *tags);

/**
 * Sets x-datadog-test-session-token on all requests for the given session.
 */
ddog_MaybeError ddog_sidecar_set_test_session_token(struct ddog_SidecarTransport **transport,
                                                    ddog_CharSlice token);

/**
 * This function creates a new transport using the provided callback function when the current
 * transport is closed.
 *
 * # Arguments
 *
 * * `transport` - The transport used for communication.
 * * `factory` - A C function that must return a pointer to "ddog_SidecarTransport"
 */
void ddog_sidecar_reconnect(struct ddog_SidecarTransport **transport,
                            struct ddog_SidecarTransport *(*factory)(void));

/**
 * Gets an agent info reader.
 */
struct ddog_AgentInfoReader *ddog_get_agent_info_reader(const struct ddog_Endpoint *endpoint);

/**
 * Gets the current agent info environment (or empty if not existing)
 */
ddog_CharSlice ddog_get_agent_info_env(struct ddog_AgentInfoReader *reader, bool *changed);

/**
 * Gets the container tags hash from agent info (or empty if not existing)
 */
ddog_CharSlice ddog_get_agent_info_container_tags_hash(struct ddog_AgentInfoReader *reader,
                                                       bool *changed);

void ddog_send_traces_to_sidecar(ddog_TracesBytes *traces,
                                 struct ddog_SenderParameters *parameters);

/**
 * V1 counterpart of `ddog_send_traces_to_sidecar`: encodes the native V1 `TracerPayload` built via
 * the [`crate::span_v1`] builder (natively, without the v0.4→v1 upgrade converter), then sends it
 * to the sidecar for the agent's `/v1.0/traces` endpoint. Consumes `builder`.
 *
 * Payload-level metadata is sourced at send time: the lang/lang_version/tracer_version and
 * container_id come from `parameters.tracer_headers_tags`, while hostname/env/app_version/
 * runtime_id/git_commit_sha come from `metadata`. lang_interpreter/lang_vendor are forwarded to
 * the sidecar as HTTP header tags (they are not part of the V1 wire payload).
 */
void ddog_send_traces_to_sidecar_v1(struct ddog_TracerPayloadV1Builder *builder,
                                    struct ddog_SenderParameters *parameters,
                                    const struct ddog_TracerMetadataV1 *metadata);

/**
 * Drops the agent info reader.
 */
void ddog_drop_agent_info_reader(struct ddog_AgentInfoReader*);

void ddog_sidecar_send_garbage(struct ddog_SidecarTransport **transport);

ddog_TracesBytes *ddog_get_traces(void);

void ddog_free_traces(ddog_TracesBytes *_traces);

uintptr_t ddog_get_traces_size(const ddog_TracesBytes *traces);

ddog_TraceBytes *ddog_get_trace(ddog_TracesBytes *traces, uintptr_t index);

ddog_TraceBytes *ddog_traces_new_trace(ddog_TracesBytes *traces);

uintptr_t ddog_get_trace_size(const ddog_TraceBytes *trace);

ddog_SpanBytes *ddog_get_span(ddog_TraceBytes *trace, uintptr_t index);

ddog_SpanBytes *ddog_trace_new_span(ddog_TraceBytes *trace);

ddog_SpanBytes *ddog_trace_new_span_with_capacities(ddog_TraceBytes *trace,
                                                    uintptr_t meta_size,
                                                    uintptr_t metrics_size);

/**
 * The returned slice is an owned allocation that must be properly freed using
 * [`ddog_free_charslice`].
 */
ddog_CharSlice ddog_span_debug_log(const ddog_SpanBytes *span);

/**
 * Frees an owned [`CharSlice`]. Note that some functions of this API return borrowed slices that
 * must NOT be freed. Only a few selected functions return slices that must be freed, and this is
 * mentioned explicitly in their documentation.
 *
 * # Safety
 *
 * `slice` must be an owned char slice that has been returned by one of the functions of this API.
 */
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

/**
 * The return value is an owned array of slices (`Box<[CharSlice]>`) that must be freed explicitly
 * through [`ddog_span_free_keys_ptr`].
 */
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

/**
 * The return value is an array of slices (`Box<[CharSlice]>`) that must be freed explicitly
 * through [`ddog_span_free_keys_ptr`].
 */
ddog_CharSlice *ddog_span_meta_struct_get_keys(ddog_SpanBytes *span, uintptr_t *out_count);

/**
 * # Safety
 *
 * `keys_ptr` must have been returned by one of the `ddog_xxx_get_keys()` functions, and must not
 * have been already freed.
 */
void ddog_span_free_keys_ptr(ddog_CharSlice *keys_ptr, uintptr_t count);

ddog_SpanLinkBytes *ddog_span_new_link(ddog_SpanBytes *span);

void ddog_set_link_tracestate(ddog_SpanLinkBytes *link, ddog_CharSlice slice);

void ddog_set_link_trace_id(ddog_SpanLinkBytes *link, uint64_t value);

void ddog_set_link_trace_id_high(ddog_SpanLinkBytes *link, uint64_t value);

void ddog_set_link_span_id(ddog_SpanLinkBytes *link, uint64_t value);

void ddog_set_link_flags(ddog_SpanLinkBytes *link, uint32_t value);

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

/**
 * The returned slice is an owned allocation that must be properly freed using
 * [`ddog_free_charslice`].
 */
ddog_CharSlice ddog_serialize_trace_into_charslice(ddog_TraceBytes *trace);

/**
 * Creates a new, empty V1 payload builder. Free it with [`ddog_v1_free_builder`], or hand it to
 * `ddog_send_traces_to_sidecar_v1`, which consumes it.
 */
struct ddog_TracerPayloadV1Builder *ddog_v1_new_builder(void);

/**
 * Frees a V1 payload builder.
 */
void ddog_v1_free_builder(struct ddog_TracerPayloadV1Builder *_builder);

/**
 * Interns `string` into the builder's value-keyed table and returns its stable id. Equal strings
 * (including across chunks/spans) always return the same id; the empty string is always id 0.
 */
uint32_t ddog_v1_intern_string(struct ddog_TracerPayloadV1Builder *builder, ddog_CharSlice string);

/**
 * Appends a new (empty) chunk with the given 128-bit trace id, returning its index.
 *
 * A chunk must be fully built (all its spans/links/events) before the next chunk is created:
 * creating a chunk may reallocate the chunk vector and invalidate positions cached as raw
 * pointers. Indices remain valid.
 */
uintptr_t ddog_v1_builder_new_chunk(struct ddog_TracerPayloadV1Builder *builder,
                                    uint64_t trace_id_high,
                                    uint64_t trace_id_low);

/**
 * Sets the chunk sampling priority (v0.4 `_sampling_priority_v1`).
 */
void ddog_v1_set_chunk_sampling_priority(struct ddog_TracerPayloadV1Builder *builder,
                                         uintptr_t chunk,
                                         int32_t priority);

/**
 * Sets the chunk origin (v0.4 `_dd.origin`) from an interned id.
 */
void ddog_v1_set_chunk_origin(struct ddog_TracerPayloadV1Builder *builder,
                              uintptr_t chunk,
                              uint32_t origin_id);

/**
 * Sets the chunk sampling mechanism (v0.4 `_dd.p.dm`).
 */
void ddog_v1_set_chunk_sampling_mechanism(struct ddog_TracerPayloadV1Builder *builder,
                                          uintptr_t chunk,
                                          uint32_t mechanism);

/**
 * Marks the chunk as a dropped (p0) trace.
 */
void ddog_v1_set_chunk_dropped_trace(struct ddog_TracerPayloadV1Builder *builder,
                                     uintptr_t chunk,
                                     bool dropped);

/**
 * Adds a string-valued chunk-level attribute (key and value are interned ids).
 */
void ddog_v1_add_chunk_attr_str(struct ddog_TracerPayloadV1Builder *builder,
                                uintptr_t chunk,
                                uint32_t key_id,
                                uint32_t value_id);

/**
 * Appends a new (empty) span to `chunk`, returning its index within that chunk.
 *
 * A span must be fully built before the next span is created in the same chunk.
 */
uintptr_t ddog_v1_chunk_new_span(struct ddog_TracerPayloadV1Builder *builder, uintptr_t chunk);

/**
 * Sets the span id.
 */
void ddog_v1_set_span_id(struct ddog_TracerPayloadV1Builder *builder,
                         uintptr_t chunk,
                         uintptr_t span,
                         uint64_t value);

/**
 * Sets the span parent id.
 */
void ddog_v1_set_span_parent_id(struct ddog_TracerPayloadV1Builder *builder,
                                uintptr_t chunk,
                                uintptr_t span,
                                uint64_t value);

/**
 * Sets the span start time (unix nanos; negative values are normalized at encode time).
 */
void ddog_v1_set_span_start(struct ddog_TracerPayloadV1Builder *builder,
                            uintptr_t chunk,
                            uintptr_t span,
                            int64_t value);

/**
 * Sets the span duration (nanos).
 */
void ddog_v1_set_span_duration(struct ddog_TracerPayloadV1Builder *builder,
                               uintptr_t chunk,
                               uintptr_t span,
                               int64_t value);

/**
 * Sets the span error flag.
 */
void ddog_v1_set_span_error(struct ddog_TracerPayloadV1Builder *builder,
                            uintptr_t chunk,
                            uintptr_t span,
                            bool error);

/**
 * Sets the span kind (OTEL wire value; unset/unknown → Internal).
 */
void ddog_v1_set_span_kind(struct ddog_TracerPayloadV1Builder *builder,
                           uintptr_t chunk,
                           uintptr_t span,
                           uint32_t kind);

/**
 * Adds a bytes-valued span attribute. The key is an interned id; the value bytes are copied
 * verbatim (not interned) and encoded as msgpack `bin`.
 */
void ddog_v1_add_span_attr_bytes(struct ddog_TracerPayloadV1Builder *builder,
                                 uintptr_t chunk,
                                 uintptr_t span,
                                 uint32_t key_id,
                                 ddog_CharSlice value);

/**
 * Appends a new (empty) link to a span, returning its index within that span.
 */
uintptr_t ddog_v1_span_new_link(struct ddog_TracerPayloadV1Builder *builder,
                                uintptr_t chunk,
                                uintptr_t span);

/**
 * Sets the link's 128-bit trace id (high/low halves).
 */
void ddog_v1_set_link_trace_id(struct ddog_TracerPayloadV1Builder *builder,
                               uintptr_t chunk,
                               uintptr_t span,
                               uintptr_t link,
                               uint64_t trace_id_high,
                               uint64_t trace_id_low);

/**
 * Sets the link span id.
 */
void ddog_v1_set_link_span_id(struct ddog_TracerPayloadV1Builder *builder,
                              uintptr_t chunk,
                              uintptr_t span,
                              uintptr_t link,
                              uint64_t value);

/**
 * Sets the link flags (W3C trace-flags plus the "set" sentinel bit).
 */
void ddog_v1_set_link_flags(struct ddog_TracerPayloadV1Builder *builder,
                            uintptr_t chunk,
                            uintptr_t span,
                            uintptr_t link,
                            uint32_t value);

/**
 * Sets the link tracestate (interned id).
 */
void ddog_v1_set_link_tracestate(struct ddog_TracerPayloadV1Builder *builder,
                                 uintptr_t chunk,
                                 uintptr_t span,
                                 uintptr_t link,
                                 uint32_t id);

/**
 * Adds a string-valued link attribute (key and value are interned ids).
 */
void ddog_v1_add_link_attr_str(struct ddog_TracerPayloadV1Builder *builder,
                               uintptr_t chunk,
                               uintptr_t span,
                               uintptr_t link,
                               uint32_t key_id,
                               uint32_t value_id);

/**
 * Appends a new (empty) event to a span, returning its index within that span.
 */
uintptr_t ddog_v1_span_new_event(struct ddog_TracerPayloadV1Builder *builder,
                                 uintptr_t chunk,
                                 uintptr_t span);

/**
 * Sets the event time (unix nanos).
 */
void ddog_v1_set_event_time(struct ddog_TracerPayloadV1Builder *builder,
                            uintptr_t chunk,
                            uintptr_t span,
                            uintptr_t event,
                            uint64_t time_unix_nano);

/**
 * Sets the event name (interned id).
 */
void ddog_v1_set_event_name(struct ddog_TracerPayloadV1Builder *builder,
                            uintptr_t chunk,
                            uintptr_t span,
                            uintptr_t event,
                            uint32_t id);

#endif  /* DDOG_SIDECAR_H */
