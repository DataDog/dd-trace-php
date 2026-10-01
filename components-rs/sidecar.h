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

ddog_MaybeError ddog_sidecar_connect_master(void);

ddog_MaybeError ddog_sidecar_connect_worker(int32_t pid, struct ddog_SidecarTransport **connection);

ddog_MaybeError ddog_sidecar_shutdown_master_listener(void);

/**
 * Remove the master listener's socket and lock file, for SAPIs that exit without running
 * PHP's module shutdown - php-fpm's master calls `exit()` straight from `fpm_pctl_exit()`, so
 * `ddog_sidecar_shutdown_master_listener` never runs there. Safe to call more than once, and a
 * no-op in a process that did not bind them.
 */
void ddog_sidecar_reap_master_listener_files(void);

bool ddog_sidecar_is_master_listener_active(void);

/**
 * # Safety
 * On Unix, call in the child after fork, before starting threads. Inherited sidecar
 * tasks and references to their state must not be used afterward.
 */
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
                                                const struct ddog_RemoteConfigNotification *win_remote_config_notification,
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
                                                ddog_CharSlice parent_session_id,
                                                bool force_v04_traces);

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
 * V1 counterpart of `ddog_send_traces_to_sidecar`: sends the [`crate::span`] builder's native V1
 * payload to the agent's `/v1.0/traces`. Consumes `builder`. Payload metadata comes at send time
 * from `parameters.tracer_headers_tags` and `metadata`; lang_interpreter/lang_vendor go as
 * headers.
 */
void ddog_send_traces_to_sidecar_v1(struct ddog_TracerPayloadV1Builder *builder,
                                    struct ddog_SenderParameters *parameters,
                                    const struct ddog_TracerMetadataV1 *metadata);

/**
 * Downgrades a native V1 builder to the in-memory v0.4 collection for the in-process `coms.c`
 * sender (PHP <= 8.2). Consumes `builder`. Returns the collection (not one whole-payload
 * CharSlice) so `auto_flush` can frame each trace individually; a single CharSlice would drop
 * extra traces of a multi-trace payload. Empty collection on error; free with
 * [`crate::span_v04::ddog_free_traces`].
 */
ddog_TracesBytes *ddog_downgrade_v1_builder_to_v04_traces(struct ddog_TracerPayloadV1Builder *builder);

/**
 * Drops the agent info reader.
 */
void ddog_drop_agent_info_reader(struct ddog_AgentInfoReader*);

void ddog_sidecar_send_garbage(struct ddog_SidecarTransport **transport);

/**
 * Sends an AppSec message from the PHP extension through the sidecar to the registered helper.
 *
 * The response is allocated by the sidecar and must be freed with
 * `ddog_sidecar_appsec_response_drop` when the caller is done with it.
 *
 * Returns a zeroed `ddog_AppsecCResponse` (null ptr) on transport errors.
 */
struct ddog_AppsecCResponse ddog_sidecar_send_appsec_message(struct ddog_SidecarTransport **transport,
                                                             uint64_t client_id,
                                                             ddog_CharSlice data);

/**
 * Sends an AppSec message once, without reconnecting the sidecar on failure.
 *
 * The response is allocated by the sidecar and must be freed with
 * `ddog_sidecar_appsec_response_drop` when the caller is done with it.
 *
 * Returns a zeroed `ddog_AppsecCResponse` (null ptr) on transport errors.
 */
struct ddog_AppsecCResponse datadog_sidecar_send_appsec_message_without_reconnect(struct ddog_SidecarTransport **transport,
                                                                                  uint64_t client_id,
                                                                                  ddog_CharSlice data);

/**
 * Frees an `AppsecCResponse` returned by an AppSec message function.
 */
void ddog_sidecar_appsec_response_drop(struct ddog_AppsecCResponse response);

#if defined(_WIN32)
/**
 * Create a Windows notification that invokes `callback(context)` when remote configuration may
 * have changed.
 *
 * On success, `*out` receives a newly allocated notification. Pass that pointer to
 * `ddog_sidecar_session_set_config` to associate it with a session, and eventually release it
 * with `ddog_sidecar_remote_config_notification_drop`. Session configuration does not take
 * ownership of the notification.
 *
 * The callback runs asynchronously on a Windows thread-pool thread. Invocations of the
 * caller-provided callback for the same notification do not overlap, but several remote
 * configuration updates may be coalesced into one callback invocation. Treat the callback as a
 * prompt to read the latest configuration rather than as a count of updates.
 *
 * If the function returns an error, a valid `out` parameter is set to NULL.
 *
 * # Safety
 *
 * - `out` must point to writable storage for one notification pointer.
 * - `callback` must be non-NULL and safe to call with `context` from a Windows thread-pool thread.
 * - If creation succeeds, the callback code and any data reached through `context` must remain
 *   valid until `ddog_sidecar_remote_config_notification_drop` returns.
 * - The callback must not drop its own notification.
 */
ddog_MaybeError ddog_sidecar_remote_config_notification_new(void (*callback)(void*),
                                                            void *context,
                                                            struct ddog_RemoteConfigNotification **out);
#endif

#if defined(_WIN32)
/**
 * Disable a remote configuration notification and release it.
 *
 * Passing NULL has no effect. If its callback is currently running, this function waits for the
 * callback to return. Once this function returns, no callback for this notification is running or
 * can start, so the caller may safely release the callback context or unload the callback code.
 * A sidecar that still has the session configuration may continue sending signals, but those
 * signals can no longer invoke the callback.
 *
 * # Safety
 *
 * - `notification` must be NULL or a live pointer returned by
 *   `ddog_sidecar_remote_config_notification_new`.
 * - A non-NULL pointer may be passed to this function only once and must not be used concurrently
 *   by another call, including `ddog_sidecar_session_set_config`.
 * - This function must not be called from the notification's callback.
 */
void ddog_sidecar_remote_config_notification_drop(struct ddog_RemoteConfigNotification *notification);
#endif

#if defined(__linux__)
/**
 * Prepare a flush on this transport with a private completion pipe.
 * Normal thread context only. The returned object owns a duplicate of the transport fd;
 * refresh it after reconnect and drop it before normal connection shutdown.
 *
 * # Safety
 * `transport` must be exclusively borrowed and `output` must be writable for this call.
 */
ddog_MaybeError ddog_sidecar_prepare_signal_flush(struct ddog_SidecarTransport *transport,
                                                  struct ddog_SidecarFlushOptions options,
                                                  struct ddog_SignalFlush **output);
#endif

#if defined(__linux__)
/**
 * Destroy a prepared flush in ordinary thread context.
 *
 * # Safety
 * `flush` must be null or an owned pointer returned by prepare. Any raw worker must have exited.
 */
void ddog_sidecar_signal_flush_drop(struct ddog_SignalFlush *flush);
#endif

#if defined(__linux__)
/**
 * Run one bounded flush without TLS access, allocation, unwinding, or process termination.
 * Returns zero when the sidecar closes the pipe (completion or exit), or a negative Linux errno.
 *
 * # Safety
 * The object must remain alive through the call, with exclusive one-shot use of this object.
 * The normal transport may continue sending and receiving concurrently.
 * All worker signals must be blocked. Do not use an inherited object after fork.
 */
int32_t ddog_sidecar_signal_flush_run(const struct ddog_SignalFlush *flush);
#endif

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
 * Sets the payload `env` / `app_version` / `hostname` fields (empty = unset).
 */
void ddog_set_payload_metadata(struct ddog_TracerPayloadV1Builder *builder,
                               ddog_CharSlice env,
                               ddog_CharSlice app_version,
                               ddog_CharSlice hostname);

/**
 * Appends a chunk carrying the 128-bit trace id (high/low halves), returning its node pointer.
 */
struct ddog_ChunkNode *ddog_new_chunk(struct ddog_TracerPayloadV1Builder *builder,
                                      uint64_t trace_id_high,
                                      uint64_t trace_id_low);

/**
 * Number of spans already in `chunk`.
 *
 * # Safety
 * `chunk` must be a live chunk node pointer from [`ddog_new_chunk`] (applies to every chunk fn).
 */
uintptr_t ddog_chunk_span_count(struct ddog_ChunkNode *chunk);

/**
 * Appends an empty span to `chunk`, returning its node pointer.
 *
 * # Safety
 * See [`ddog_chunk_span_count`].
 */
struct ddog_SpanNode *ddog_new_span(struct ddog_ChunkNode *chunk);

/**
 * Appends an empty link to `span`, returning its node pointer.
 *
 * # Safety
 * `span` must be a live span node pointer from [`ddog_new_span`] (applies to every span fn).
 */
ddog_SpanLinkBytes *ddog_new_link(struct ddog_SpanNode *span);

/**
 * Appends an empty event to `span`, returning its node pointer.
 *
 * # Safety
 * See [`ddog_new_link`].
 */
ddog_SpanEventBytes *ddog_new_event(struct ddog_SpanNode *span);

/**
 * # Safety
 * See [`ddog_new_link`] (applies to every `ddog_span_set_*` / `ddog_set_span_*`).
 */
void ddog_span_set_id(struct ddog_SpanNode *span, uint64_t value);

/**
 * # Safety
 * See [`ddog_span_set_id`].
 */
void ddog_span_set_parent_id(struct ddog_SpanNode *span, uint64_t value);

/**
 * # Safety
 * See [`ddog_span_set_id`].
 */
void ddog_span_set_start(struct ddog_SpanNode *span, int64_t value);

/**
 * # Safety
 * See [`ddog_span_set_id`].
 */
void ddog_span_set_duration(struct ddog_SpanNode *span, int64_t value);

/**
 * # Safety
 * See [`ddog_span_set_id`].
 */
void ddog_span_set_error(struct ddog_SpanNode *span, bool error);

/**
 * Reads the span error flag (e.g. to mirror it onto an inferred span).
 *
 * # Safety
 * See [`ddog_new_link`].
 */
bool ddog_span_get_error(struct ddog_SpanNode *span);

/**
 * # Safety
 * See [`ddog_span_get_error`].
 */
void ddog_set_span_service(struct ddog_SpanNode *span, ddog_CharSlice value);

/**
 * # Safety
 * See [`ddog_span_get_error`].
 */
void ddog_set_span_name(struct ddog_SpanNode *span, ddog_CharSlice value);

/**
 * # Safety
 * See [`ddog_span_get_error`].
 */
void ddog_set_span_resource(struct ddog_SpanNode *span, ddog_CharSlice value);

/**
 * # Safety
 * See [`ddog_span_get_error`].
 */
void ddog_set_span_type(struct ddog_SpanNode *span, ddog_CharSlice value);

/**
 * # Safety
 * See [`ddog_span_get_error`].
 */
void ddog_set_span_env(struct ddog_SpanNode *span, ddog_CharSlice value);

/**
 * # Safety
 * See [`ddog_span_get_error`].
 */
void ddog_set_span_version(struct ddog_SpanNode *span, ddog_CharSlice value);

/**
 * # Safety
 * See [`ddog_span_get_error`].
 */
void ddog_set_span_component(struct ddog_SpanNode *span, ddog_CharSlice value);

/**
 * Sets the span kind from an OTEL wire value (unset/unknown → Unspecified).
 *
 * # Safety
 * See [`ddog_new_link`].
 */
void ddog_set_span_kind(struct ddog_SpanNode *span, uint32_t kind);

/**
 * Sets the span kind from a v0.4 `span.kind` string. Returns `false` for a non-canonical kind,
 * which has no wire slot, so the caller keeps it as a plain attribute.
 *
 * # Safety
 * See [`ddog_new_link`].
 */
bool ddog_set_span_kind_str(struct ddog_SpanNode *span, ddog_CharSlice value);

/**
 * # Safety
 * See [`ddog_chunk_span_count`] (applies to every `ddog_set_chunk_*`).
 */
void ddog_set_chunk_origin(struct ddog_ChunkNode *chunk, ddog_CharSlice origin);

/**
 * # Safety
 * See [`ddog_set_chunk_origin`].
 */
void ddog_set_chunk_sampling_priority(struct ddog_ChunkNode *chunk, int32_t priority);

/**
 * # Safety
 * See [`ddog_set_chunk_origin`].
 */
void ddog_set_chunk_sampling_mechanism(struct ddog_ChunkNode *chunk, uint32_t mechanism);

/**
 * # Safety
 * `link` must be a live link node pointer from [`ddog_new_link`] (applies to every
 * `ddog_link_*`).
 */
void ddog_link_set_trace_id(ddog_SpanLinkBytes *link,
                            uint64_t trace_id_high,
                            uint64_t trace_id_low);

/**
 * # Safety
 * See [`ddog_link_set_trace_id`].
 */
void ddog_link_set_span_id(ddog_SpanLinkBytes *link, uint64_t value);

/**
 * # Safety
 * See [`ddog_link_set_trace_id`].
 */
void ddog_link_set_flags(ddog_SpanLinkBytes *link, uint32_t value);

/**
 * # Safety
 * See [`ddog_link_set_trace_id`].
 */
void ddog_link_set_tracestate(ddog_SpanLinkBytes *link, ddog_CharSlice value);

/**
 * # Safety
 * `event` must be a live event node pointer from [`ddog_new_event`] (applies to every
 * `ddog_event_*`).
 */
void ddog_event_set_name(ddog_SpanEventBytes *event, ddog_CharSlice value);

/**
 * # Safety
 * See [`ddog_event_set_name`].
 */
void ddog_event_set_time(ddog_SpanEventBytes *event, uint64_t time_unix_nano);

/**
 * The payload's attribute map.
 *
 * # Safety
 * `builder` must be a live builder from [`ddog_v1_new_builder`].
 */
struct ddog_Attributes *ddog_payload_get_attributes(struct ddog_TracerPayloadV1Builder *builder);

/**
 * # Safety
 * See [`ddog_chunk_span_count`].
 */
struct ddog_Attributes *ddog_chunk_get_attributes(struct ddog_ChunkNode *chunk);

/**
 * # Safety
 * See [`ddog_new_link`].
 */
struct ddog_Attributes *ddog_span_get_attributes(struct ddog_SpanNode *span);

/**
 * # Safety
 * See [`ddog_link_set_trace_id`].
 */
struct ddog_Attributes *ddog_link_get_attributes(ddog_SpanLinkBytes *link);

/**
 * # Safety
 * See [`ddog_event_set_name`].
 */
struct ddog_Attributes *ddog_event_get_attributes(ddog_SpanEventBytes *event);

/**
 * # Safety
 * `attrs` must be a live [`Attributes`] handle (applies to every `ddog_attributes_add_*`).
 */
void ddog_attributes_add_str(struct ddog_Attributes *attrs,
                             ddog_CharSlice key,
                             ddog_CharSlice value);

/**
 * # Safety
 * See [`ddog_attributes_add_str`].
 */
void ddog_attributes_add_int(struct ddog_Attributes *attrs, ddog_CharSlice key, int64_t value);

/**
 * # Safety
 * See [`ddog_attributes_add_str`].
 */
void ddog_attributes_add_double(struct ddog_Attributes *attrs, ddog_CharSlice key, double value);

/**
 * # Safety
 * See [`ddog_attributes_add_str`].
 */
void ddog_attributes_add_bool(struct ddog_Attributes *attrs, ddog_CharSlice key, bool value);

/**
 * Bytes attribute (v0.4 `meta_struct`), copied verbatim.
 *
 * # Safety
 * See [`ddog_attributes_add_str`].
 */
void ddog_attributes_add_bytes(struct ddog_Attributes *attrs,
                               ddog_CharSlice key,
                               ddog_CharSlice value);

/**
 * Sets `attrs[key]` to `list`, which is consumed.
 *
 * # Safety
 * See [`ddog_attributes_add_str`]; `list` must be a live list from [`ddog_attr_list_new`].
 */
void ddog_attributes_add_list(struct ddog_Attributes *attrs,
                              ddog_CharSlice key,
                              struct ddog_AttrList *list);

/**
 * Sets `attrs[key]` to `map`, which is consumed.
 *
 * # Safety
 * See [`ddog_attributes_add_str`]; `map` must be a live map from [`ddog_attr_map_new`], other than
 * the one `attrs` belongs to.
 */
void ddog_attributes_add_map(struct ddog_Attributes *attrs,
                             ddog_CharSlice key,
                             struct ddog_AttrMap *map);

/**
 * Copies the attribute `key` from `from_span` onto `to_span`, returning whether the source had it;
 * removes it from the source when `delete_source` is set. Type-preserving.
 *
 * # Safety
 * `from_span`/`to_span` must be live, distinct span node pointers from [`ddog_new_span`]; `key`
 * a static NUL-terminated string.
 */
bool ddog_transfer_span_attr(struct ddog_SpanNode *from_span,
                             struct ddog_SpanNode *to_span,
                             const char *key,
                             bool delete_source);

/**
 * Allocates an empty list with room for `capacity` elements, owned by C until it is consumed.
 */
struct ddog_AttrList *ddog_attr_list_new(uintptr_t capacity);

/**
 * Allocates an empty map with room for `capacity` members, owned by C until it is consumed.
 */
struct ddog_AttrMap *ddog_attr_map_new(uintptr_t capacity);

/**
 * The attribute map of an owned nested `map`, filled with the `ddog_attributes_add_*` family.
 *
 * # Safety
 * `map` must be a live map from [`ddog_attr_map_new`].
 */
struct ddog_Attributes *ddog_attr_map_get_attributes(struct ddog_AttrMap *map);

/**
 * # Safety
 * `list` must be a live list from [`ddog_attr_list_new`] (applies to every `ddog_attr_list_*`);
 * a `child` is consumed.
 */
void ddog_attr_list_push_str(struct ddog_AttrList *list, ddog_CharSlice value);

/**
 * # Safety
 * See [`ddog_attr_list_push_str`].
 */
void ddog_attr_list_push_int(struct ddog_AttrList *list, int64_t value);

/**
 * # Safety
 * See [`ddog_attr_list_push_str`].
 */
void ddog_attr_list_push_double(struct ddog_AttrList *list, double value);

/**
 * # Safety
 * See [`ddog_attr_list_push_str`].
 */
void ddog_attr_list_push_bool(struct ddog_AttrList *list, bool value);

/**
 * # Safety
 * See [`ddog_attr_list_push_str`].
 */
void ddog_attr_list_push_bytes(struct ddog_AttrList *list, ddog_CharSlice value);

/**
 * # Safety
 * See [`ddog_attr_list_push_str`].
 */
void ddog_attr_list_push_list(struct ddog_AttrList *list, struct ddog_AttrList *child);

/**
 * # Safety
 * See [`ddog_attr_list_push_str`].
 */
void ddog_attr_list_push_map(struct ddog_AttrList *list, struct ddog_AttrMap *child);

/**
 * The builder's chunk node pointers; `len` receives their count.
 */
struct ddog_ChunkNode *const *ddog_v1_get_chunks(const struct ddog_TracerPayloadV1Builder *builder,
                                                 uintptr_t *len);

/**
 * Number of chunks in the builder.
 */
uintptr_t ddog_v1_get_chunk_count(const struct ddog_TracerPayloadV1Builder *builder);

/**
 * The chunk's span node pointers; `len` receives their count.
 */
struct ddog_SpanNode *const *ddog_v1_get_spans(const struct ddog_ChunkNode *chunk, uintptr_t *len);

/**
 * The chunk's local-root span, as the v0.4 wire picks it (`local_root_idx`), or null if empty.
 * Chunk-level trace tags (trace_id_high, sampling priority/mechanism, origin) belong on it only.
 */
struct ddog_SpanNode *ddog_v1_get_chunk_root_span(const struct ddog_ChunkNode *chunk);

/**
 * The span's link node pointers; `len` receives their count.
 */
ddog_SpanLinkBytes *const *ddog_v1_get_links(const struct ddog_SpanNode *span, uintptr_t *len);

/**
 * The span's event node pointers; `len` receives their count.
 */
ddog_SpanEventBytes *const *ddog_v1_get_events(const struct ddog_SpanNode *span, uintptr_t *len);

uint64_t ddog_v1_get_chunk_trace_id_high(const struct ddog_ChunkNode *chunk);

uint64_t ddog_v1_get_chunk_trace_id_low(const struct ddog_ChunkNode *chunk);

/**
 * Reads the chunk sampling priority; returns `false` (and leaves `out` untouched) when unset.
 */
bool ddog_v1_get_chunk_sampling_priority(const struct ddog_ChunkNode *chunk, int32_t *out);

/**
 * Reads the chunk sampling mechanism; returns `false` (and leaves `out` untouched) when unset.
 */
bool ddog_v1_get_chunk_sampling_mechanism(const struct ddog_ChunkNode *chunk, uint32_t *out);

ddog_CharSlice ddog_v1_get_chunk_origin(const struct ddog_ChunkNode *chunk);

ddog_CharSlice ddog_v1_get_span_service(const struct ddog_SpanNode *span);

ddog_CharSlice ddog_v1_get_span_name(const struct ddog_SpanNode *span);

ddog_CharSlice ddog_v1_get_span_resource(const struct ddog_SpanNode *span);

ddog_CharSlice ddog_v1_get_span_type(const struct ddog_SpanNode *span);

ddog_CharSlice ddog_v1_get_span_env(const struct ddog_SpanNode *span);

ddog_CharSlice ddog_v1_get_span_version(const struct ddog_SpanNode *span);

ddog_CharSlice ddog_v1_get_span_component(const struct ddog_SpanNode *span);

uint64_t ddog_v1_get_span_id(const struct ddog_SpanNode *span);

uint64_t ddog_v1_get_span_parent_id(const struct ddog_SpanNode *span);

int64_t ddog_v1_get_span_start(const struct ddog_SpanNode *span);

int64_t ddog_v1_get_span_duration(const struct ddog_SpanNode *span);

bool ddog_v1_get_span_error(const struct ddog_SpanNode *span);

/**
 * The span kind as its OTEL wire value.
 */
uint32_t ddog_v1_get_span_kind(const struct ddog_SpanNode *span);

uint64_t ddog_v1_get_link_trace_id_high(const ddog_SpanLinkBytes *link);

uint64_t ddog_v1_get_link_trace_id_low(const ddog_SpanLinkBytes *link);

uint64_t ddog_v1_get_link_span_id(const ddog_SpanLinkBytes *link);

uint32_t ddog_v1_get_link_flags(const ddog_SpanLinkBytes *link);

ddog_CharSlice ddog_v1_get_link_tracestate(const ddog_SpanLinkBytes *link);

uint64_t ddog_v1_get_event_time(const ddog_SpanEventBytes *event);

ddog_CharSlice ddog_v1_get_event_name(const ddog_SpanEventBytes *event);

/**
 * Number of entries in `attrs`, including not-yet-deduped repeated keys (the last one wins).
 *
 * # Safety
 * `attrs` must be a live [`Attributes`] handle (applies to every `ddog_v1_attributes_*`).
 */
uintptr_t ddog_v1_attributes_len(const struct ddog_Attributes *attrs);

/**
 * Key of the entry at `idx` (empty if out of range).
 *
 * # Safety
 * See [`ddog_v1_attributes_len`].
 */
ddog_CharSlice ddog_v1_attributes_key(const struct ddog_Attributes *attrs, uintptr_t idx);

/**
 * Value of the entry at `idx` (null if out of range).
 *
 * # Safety
 * See [`ddog_v1_attributes_len`].
 */
const struct ddog_AttrValue *ddog_v1_attributes_value(const struct ddog_Attributes *attrs,
                                                      uintptr_t idx);

/**
 * Value of `key` (null if absent).
 *
 * # Safety
 * See [`ddog_v1_attributes_len`].
 */
const struct ddog_AttrValue *ddog_v1_attributes_get(const struct ddog_Attributes *attrs,
                                                    ddog_CharSlice key);

/**
 * [`DDOG_V1_ATTR_*`] type tag of `value`.
 *
 * # Safety
 * `value` must be a live [`AttrValue`] handle (applies to every `ddog_v1_value_*`).
 */
uint32_t ddog_v1_value_type(const struct ddog_AttrValue *value);

/**
 * The string (empty unless a `String`).
 *
 * # Safety
 * See [`ddog_v1_value_type`].
 */
ddog_CharSlice ddog_v1_value_str(const struct ddog_AttrValue *value);

/**
 * The bytes (empty unless `Bytes`).
 *
 * # Safety
 * See [`ddog_v1_value_type`].
 */
ddog_CharSlice ddog_v1_value_bytes(const struct ddog_AttrValue *value);

/**
 * The integer (0 unless an `Int`).
 *
 * # Safety
 * See [`ddog_v1_value_type`].
 */
int64_t ddog_v1_value_int(const struct ddog_AttrValue *value);

/**
 * The double (0.0 unless a `Float`).
 *
 * # Safety
 * See [`ddog_v1_value_type`].
 */
double ddog_v1_value_double(const struct ddog_AttrValue *value);

/**
 * The boolean (false unless a true `Bool`).
 *
 * # Safety
 * See [`ddog_v1_value_type`].
 */
bool ddog_v1_value_bool(const struct ddog_AttrValue *value);

/**
 * Number of elements (0 unless a `List`).
 *
 * # Safety
 * See [`ddog_v1_value_type`].
 */
uintptr_t ddog_v1_value_list_len(const struct ddog_AttrValue *value);

/**
 * Element `idx` (null unless a `List` with that element).
 *
 * # Safety
 * See [`ddog_v1_value_type`].
 */
const struct ddog_AttrValue *ddog_v1_value_list_get(const struct ddog_AttrValue *value,
                                                    uintptr_t idx);

/**
 * The members of a `KeyValue`, read with the `ddog_v1_attributes_*` family (null otherwise).
 *
 * # Safety
 * See [`ddog_v1_value_type`].
 */
const struct ddog_Attributes *ddog_v1_value_map(const struct ddog_AttrValue *value);

/**
 * Renders a span for dd-trace-php's `DD_TRACE_DEBUG` "Encoding span" line. Takes the owning
 * chunk/span node pointers (the outer frame's still-live handles), so it works mid-build before
 * the nodes are folded into the inline payload. The returned owned slice must be freed with
 * [`ddog_free_charslice`].
 *
 * # Safety
 * `chunk`/`span` must be live node pointers previously returned by
 * `ddog_new_chunk`/`ddog_new_span` (with `span` a span of `chunk`).
 */
ddog_CharSlice ddog_v1_span_debug_log(struct ddog_ChunkNode *chunk, struct ddog_SpanNode *span);

/**
 * Frees an owned [`CharSlice`]. Only the few functions that document it return owned slices (the
 * V1 [`ddog_v1_span_debug_log`] and the v0.4
 * [`crate::span_v04::ddog_serialize_trace_into_charslice`]); borrowed slices must NOT be passed
 * here. An owned slice allocates `len + 1` bytes (payload + NUL), reclaimed here with that exact
 * shape; a zero-length slice is always borrowed and owns nothing.
 *
 * # Safety
 *
 * `slice` must be an owned char slice that has been returned by one of the functions of this API.
 */
void ddog_free_charslice(ddog_CharSlice slice);

void ddog_free_traces(ddog_TracesBytes *_traces);

uintptr_t ddog_get_traces_size(const ddog_TracesBytes *traces);

ddog_TraceBytes *ddog_get_trace(ddog_TracesBytes *traces, uintptr_t index);

/**
 * Serializes one v0.4 trace as a msgpack array-of-1, the framing the background sender
 * (`ddtrace_send_traces_via_thread`) expects. Returns an owned slice; free with
 * [`crate::span::ddog_free_charslice`].
 */
ddog_CharSlice ddog_serialize_trace_into_charslice(ddog_TraceBytes *trace);

#endif  /* DDOG_SIDECAR_H */
