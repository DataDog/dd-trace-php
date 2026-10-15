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
 * This creates Rust `PlatformHandle<File>` from supplied C std FILE object.
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

struct ddog_RuntimeMetadata *ddog_sidecar_runtimeMeta_build(ddog_CharSlice language_name,
                                                            ddog_CharSlice language_version,
                                                            ddog_CharSlice tracer_version);

void ddog_sidecar_runtimeMeta_drop(struct ddog_RuntimeMetadata *meta);

/**
 * Reports the runtime configuration to the telemetry.
 */
ddog_MaybeError ddog_sidecar_telemetry_enqueueConfig(struct ddog_SidecarTransport **transport,
                                                     ddog_CharSlice config_key,
                                                     ddog_CharSlice config_value,
                                                     enum ddog_ConfigurationOrigin origin,
                                                     ddog_CharSlice config_id,
                                                     struct ddog_Option_U64 seq_id);

/**
 * Reports an endpoint to the telemetry.
 */
ddog_MaybeError ddog_sidecar_telemetry_addEndpoint(struct ddog_SidecarTransport **transport,
                                                   enum ddog_Method method,
                                                   ddog_CharSlice path,
                                                   ddog_CharSlice operation_name,
                                                   ddog_CharSlice resource_name);

/**
 * Reports a dependency to the telemetry.
 */
ddog_MaybeError ddog_sidecar_telemetry_addDependency(struct ddog_SidecarTransport **transport,
                                                     ddog_CharSlice dependency_name,
                                                     ddog_CharSlice dependency_version);

/**
 * Reports an integration to the telemetry.
 */
ddog_MaybeError ddog_sidecar_telemetry_addIntegration(struct ddog_SidecarTransport **transport,
                                                      ddog_CharSlice integration_name,
                                                      ddog_CharSlice integration_version,
                                                      bool integration_enabled);

/**
 * Stops the telemetry of the current application and clears it.
 */
ddog_MaybeError ddog_sidecar_lifecycle_end(struct ddog_SidecarTransport **transport);

/**
 * Clears the application of the current request, at request end.
 */
ddog_MaybeError ddog_sidecar_application_remove(struct ddog_SidecarTransport **transport);

/**
 * Flushes the telemetry data.
 */
ddog_MaybeError ddog_sidecar_telemetry_flush(struct ddog_SidecarTransport **transport);

/**
 * Returns whether the sidecar transport is closed or not.
 */
bool ddog_sidecar_is_closed(struct ddog_SidecarTransport **transport);

/**
 * Sets the identity and configuration of the connection. It is kept by the transport and
 * replayed after reconnects.
 *
 * `default_service_name` is the process-stable service name the tracer resolves when `DD_SERVICE`
 * is not set, reported as `svc.auto:<name>`. Pass an empty `CharSlice` if it is not reported.
 */
ddog_MaybeError ddog_sidecar_set_connection_config(struct ddog_SidecarTransport **transport,
                                                   const struct ddog_InstanceId *instance_id,
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
                                                   const struct ddog_Vec_Tag *process_tags,
                                                   ddog_CharSlice default_service_name,
                                                   ddog_CharSlice hostname,
                                                   ddog_CharSlice root_service,
                                                   ddog_CharSlice root_session_id,
                                                   ddog_CharSlice parent_session_id,
                                                   bool force_v04_traces);

/**
 * Updates the process_tags of the connection.
 */
ddog_MaybeError ddog_sidecar_set_process_tags(struct ddog_SidecarTransport **transport,
                                              const struct ddog_Vec_Tag *process_tags);

/**
 * Records whether `DD_SERVICE` is currently set (per-request mutable; refresh
 * on each RINIT). When `true` the sidecar emits `svc.user:true`; when `false`
 * it falls back to the `svc.auto:<name>` of the connection configuration (if any).
 */
ddog_MaybeError ddog_sidecar_set_user_service_defined(struct ddog_SidecarTransport **transport,
                                                      bool is_user_defined);

/**
 * Sends a trace to the sidecar via shared memory.
 */
ddog_MaybeError ddog_sidecar_send_trace_v04_shm(struct ddog_SidecarTransport **transport,
                                                struct ddog_ShmHandle *shm_handle,
                                                uintptr_t len,
                                                const struct ddog_TracerHeaderTags *tracer_header_tags);

/**
 * Sends a trace as bytes to the sidecar.
 */
ddog_MaybeError ddog_sidecar_send_trace_v04_bytes(struct ddog_SidecarTransport **transport,
                                                  ddog_CharSlice data,
                                                  const struct ddog_TracerHeaderTags *tracer_header_tags);

/**
 * Sends a V1-encoded trace to the sidecar via shared memory. The sidecar decodes the V1
 * `TracerPayload`, can inspect it, and re-encodes it as V1 msgpack on the way to the agent's
 * `/v1.0/traces` endpoint.
 */
ddog_MaybeError ddog_sidecar_send_trace_v1_shm(struct ddog_SidecarTransport **transport,
                                               struct ddog_ShmHandle *shm_handle,
                                               uintptr_t len,
                                               const struct ddog_TracerHeaderTags *tracer_header_tags);

/**
 * Sends a V1-encoded trace as bytes to the sidecar. The sidecar decodes the V1 `TracerPayload`,
 * can inspect it, and re-encodes it as V1 msgpack on the way to the agent's `/v1.0/traces`
 * endpoint.
 */
ddog_MaybeError ddog_sidecar_send_trace_v1_bytes(struct ddog_SidecarTransport **transport,
                                                 ddog_CharSlice data,
                                                 const struct ddog_TracerHeaderTags *tracer_header_tags);

ddog_MaybeError ddog_sidecar_send_debugger_data(struct ddog_SidecarTransport **transport,
                                                struct ddog_Vec_DebuggerPayload payloads);

ddog_MaybeError ddog_sidecar_send_debugger_datum(struct ddog_SidecarTransport **transport,
                                                 struct ddog_DebuggerPayload *payload);

/**
 * Configure one shared EVP intake target in this connection's sidecar session.
 * `AgentOnly` preserves the historical fixed EVP v2 route.
 * `PreferLocalThenDirect` explicitly opts the client into local discovery and
 * authenticated direct fallback.
 *
 * Call after setting the connection configuration. Other intake targets are
 * unaffected. Identical configuration preserves routing and deduplication
 * state; changed configuration replaces this target's transport. The client
 * retains the latest configuration per target across sidecar reconnects and
 * forks. Each new connection or forked instance starts with fresh route state.
 *
 * # Safety
 * `direct_endpoint` must be null or point to a valid `Endpoint`, and all
 * string slices must remain valid for the duration of this call.
 */
ddog_MaybeError ddog_sidecar_session_set_evp_transport(struct ddog_SidecarTransport **transport,
                                                       enum ddog_EvpTransportMode mode,
                                                       const struct ddog_Endpoint *agent_endpoint,
                                                       const struct ddog_Endpoint *direct_endpoint,
                                                       ddog_CharSlice intake_subdomain,
                                                       const struct ddog_EvpProducerIdentity *producer);

/**
 * Send structured Feature Flags exposure events to the sidecar. The sidecar owns
 * route selection, deduplication, JSON serialization, and EVP delivery. This
 * function is caller-driven; shared libdatadog evaluator calls do not log unless
 * an SDK explicitly sends this action.
 *
 * # Safety
 * `context` and every element in `exposures` must contain valid UTF-8
 * `CharSlice` values. Empty `exposures` is a no-op.
 */
ddog_MaybeError ddog_sidecar_send_ffe_exposure_batch(struct ddog_SidecarTransport **transport,
                                                     const struct ddog_FfeTelemetryContext *context,
                                                     struct ddog_Slice_FfeExposure exposures);

/**
 * Send structured Feature Flags evaluation events to the sidecar. The sidecar owns
 * route selection, JSON serialization, and EVP delivery. This function is
 * caller-driven; callers must aggregate and bound event cardinality before passing
 * a batch.
 *
 * # Safety
 * All slices must reference valid memory under the CharSlice contract. Context
 * metadata and flag keys must be UTF-8. Malformed optional variant, allocation,
 * rule, targeting, and context text is omitted; malformed error text becomes GENERAL.
 * A null/zero targeting slice means missing; a non-null empty slice means empty.
 * Empty `flag_evaluations` is a no-op. Use headers and library from the same build.
 */
ddog_MaybeError ddog_sidecar_send_ffe_flag_evaluation_batch(struct ddog_SidecarTransport **transport,
                                                            const struct ddog_FfeTelemetryContext *context,
                                                            struct ddog_Slice_FfeFlagEvaluation flag_evaluations);

/**
 * Send structured Feature Flags evaluation metric events to the sidecar. The sidecar
 * owns aggregation, OTLP/protobuf serialization, and OTLP HTTP delivery. This
 * function is caller-driven so SDKs with existing host-language hooks can
 * safely coexist until they explicitly migrate.
 *
 * # Safety
 * `context` and every element in `metrics` must contain valid UTF-8
 * `CharSlice` values. Empty `metrics` is a no-op.
 */
ddog_MaybeError ddog_sidecar_send_ffe_evaluation_metrics(struct ddog_SidecarTransport **transport,
                                                         const struct ddog_FfeTelemetryContext *context,
                                                         struct ddog_Slice_FfeEvaluationMetric metrics);

ddog_MaybeError ddog_sidecar_send_debugger_diagnostics(struct ddog_SidecarTransport **transport,
                                                       struct ddog_DebuggerPayload diagnostics_payload);

/**
 * Sets the application of the request currently processed on this connection. Unchanged
 * applications are not resent.
 */
ddog_MaybeError ddog_sidecar_set_application(struct ddog_SidecarTransport **transport,
                                             ddog_CharSlice service_name,
                                             ddog_CharSlice env_name,
                                             ddog_CharSlice app_version,
                                             const struct ddog_Vec_Tag *global_tags,
                                             enum ddog_DynamicInstrumentationConfigState dynamic_instrumentation_state,
                                             uint64_t remote_config_generation);

/**
 * Updates the dynamic instrumentation state of the current application.
 */
ddog_MaybeError ddog_sidecar_set_request_config(struct ddog_SidecarTransport **transport,
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
                                             ddog_CharSlice metric,
                                             int64_t value,
                                             const struct ddog_Vec_Tag *tags);

/**
 * Send a DogStatsD "distribution" metric.
 */
ddog_MaybeError ddog_sidecar_dogstatsd_distribution(struct ddog_SidecarTransport **transport,
                                                    ddog_CharSlice metric,
                                                    double value,
                                                    const struct ddog_Vec_Tag *tags);

/**
 * Send a DogStatsD "gauge" metric.
 */
ddog_MaybeError ddog_sidecar_dogstatsd_gauge(struct ddog_SidecarTransport **transport,
                                             ddog_CharSlice metric,
                                             double value,
                                             const struct ddog_Vec_Tag *tags);

/**
 * Send a DogStatsD "histogram" metric.
 */
ddog_MaybeError ddog_sidecar_dogstatsd_histogram(struct ddog_SidecarTransport **transport,
                                                 ddog_CharSlice metric,
                                                 double value,
                                                 const struct ddog_Vec_Tag *tags);

/**
 * Send a DogStatsD "set" metric.
 */
ddog_MaybeError ddog_sidecar_dogstatsd_set(struct ddog_SidecarTransport **transport,
                                           ddog_CharSlice metric,
                                           int64_t value,
                                           const struct ddog_Vec_Tag *tags);

/**
 * Sets x-datadog-test-session-token on all requests of this connection.
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
 * Continues on the connection of `connection` as another instance, keeping the state of
 * `transport`. A fork child must do so with the transport it inherited: that connection still
 * works, so `ddog_sidecar_reconnect` would keep it, but it belongs to the parent.
 *
 * # Arguments
 *
 * * `transport` - The inherited transport, whose connection is not used anymore.
 * * `connection` - A new transport of the child, without configuration.
 * * `instance_id` - The instance id of the child.
 * * `remote_config_generation` - The remote config generation last read by the child.
 */
void ddog_sidecar_transport_replace_connection_as(struct ddog_SidecarTransport **transport,
                                                  struct ddog_SidecarTransport *connection,
                                                  const struct ddog_InstanceId *instance_id,
                                                  uint64_t remote_config_generation);

/**
 * Closes the sockets of a transport inherited by a fork child from a thread which did not
 * survive the fork, so that the sidecar drops the state of that thread.
 *
 * # Safety
 *
 * The transport must never be used or dropped afterwards, but leaked: the thread may have been
 * in the middle of changing it, and its fd numbers may get reused.
 */
void ddog_sidecar_transport_close_inherited_fds(struct ddog_SidecarTransport **transport);

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
void ddog_send_traces_to_sidecar_v1(ddog_TracerPayloadBytes *builder,
                                    struct ddog_SenderParameters *parameters,
                                    const struct ddog_TracerMetadataV1 *metadata);

/**
 * Downgrades a native V1 builder to the in-memory v0.4 collection for the in-process `coms.c`
 * sender (DD_TRACE_SIDECAR_TRACE_SENDER=0). Consumes `builder`. Returns the collection (not one
 * whole-payload CharSlice) so `auto_flush` can frame each trace individually; a single CharSlice
 * would drop extra traces of a multi-trace payload. Empty collection on error; free with
 * [`crate::span_v04::ddog_free_traces`].
 */
ddog_TracesBytes *ddog_downgrade_v1_builder_to_v04_traces(ddog_TracerPayloadBytes *builder);

/**
 * Drops the agent info reader.
 */
void ddog_drop_agent_info_reader(struct ddog_AgentInfoReader*);

void ddog_sidecar_send_garbage(struct ddog_SidecarTransport **transport);

/**
 * Sends an AppSec message from the PHP extension through the sidecar to the helper's client of
 * this connection. A client_init message, as told by `client_init`, starts a new client.
 *
 * The response is allocated by the sidecar and must be freed with
 * `ddog_sidecar_appsec_response_drop` when the caller is done with it.
 *
 * Returns a zeroed `ddog_AppsecCResponse` (null ptr) on transport errors.
 */
struct ddog_AppsecCResponse ddog_sidecar_send_appsec_message(struct ddog_SidecarTransport **transport,
                                                             ddog_CharSlice data,
                                                             bool client_init);

/**
 * Sends an AppSec message once, without reconnecting the sidecar on failure.
 *
 * The response is allocated by the sidecar and must be freed with
 * `ddog_sidecar_appsec_response_drop` when the caller is done with it.
 *
 * Returns a zeroed `ddog_AppsecCResponse` (null ptr) on transport errors.
 */
struct ddog_AppsecCResponse datadog_sidecar_send_appsec_message_without_reconnect(struct ddog_SidecarTransport **transport,
                                                                                  ddog_CharSlice data,
                                                                                  bool client_init);

/**
 * Frees an `AppsecCResponse` returned by an AppSec message function.
 */
void ddog_sidecar_appsec_response_drop(struct ddog_AppsecCResponse response);

/**
 * Submit one evaluation through the shared sender without reconnecting or
 * waiting for capacity/delivery. The transport is exclusively owned by its caller;
 * platform synchronization may briefly wait. Bounded input conversion happens
 * before shared load shedding;
 * rejected observations are not retained. `Accepted` is not a delivery guarantee.
 * Oversized observations are retried once without targeting key or event context;
 * a successfully accepted reduced observation also returns `Accepted`. Required
 * metadata and optional flag dimensions must still fit the IPC packet limit.
 * The sidecar owns hashing, aggregation, final EVP encoding and HTTP delivery.
 *
 * Context comes exclusively from `attributes`; `evaluation_context_json` is
 * ignored. The row must have count one and equal first/last/evaluation timestamps.
 * Protected mode does not inspect context. Invalid optional fields are omitted;
 * a snapshot failure retains the evaluation without context. Use matching headers
 * and library. No diagnostic is recursively submitted through this EVP path.
 *
 * # Safety
 * All references must be valid for the call, with exclusive access to the transport.
 * Non-null slices that are read must
 * point to live, aligned backing storage of their stated lengths. String bytes
 * need not be UTF-8: malformed optional text is omitted. All C booleans must be
 * valid boolean values. No pointers survive this call.
 */
enum ddog_FfeSubmissionStatus ddog_sidecar_try_submit_ffe_flag_evaluation(struct ddog_SidecarTransport *transport,
                                                                          const struct ddog_FfeTelemetryContext *context,
                                                                          const struct ddog_FfeFlagEvaluation *event,
                                                                          struct ddog_Slice_FfeScalarAttribute attributes,
                                                                          const struct ddog_FfeSnapshotState *snapshot);

#if defined(_WIN32)
/**
 * Create a Windows notification that invokes `callback(context)` when remote configuration may
 * have changed.
 *
 * On success, `*out` receives a newly allocated notification. Pass that pointer to
 * `ddog_sidecar_set_connection_config` to associate it with a connection, and eventually release
 * it with `ddog_sidecar_remote_config_notification_drop`. The connection configuration does not
 * take ownership of the notification.
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
 * A sidecar that still has the connection configuration may continue sending signals, but those
 * signals can no longer invoke the callback.
 *
 * # Safety
 *
 * - `notification` must be NULL or a live pointer returned by
 *   `ddog_sidecar_remote_config_notification_new`.
 * - A non-NULL pointer may be passed to this function only once and must not be used concurrently
 *   by another call, including `ddog_sidecar_set_connection_config`.
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
ddog_TracerPayloadBytes *ddog_v1_new_builder(void);

/**
 * Frees a V1 payload builder.
 */
void ddog_v1_free_builder(ddog_TracerPayloadBytes *_builder);

/**
 * Sets the payload `env` / `app_version` / `hostname` fields (empty = unset).
 */
void ddog_set_payload_metadata(ddog_TracerPayloadBytes *builder,
                               ddog_CharSlice env,
                               ddog_CharSlice app_version,
                               ddog_CharSlice hostname);

/**
 * Appends a chunk carrying the 128-bit trace id (high/low halves).
 */
ddog_TraceChunkBytes *ddog_new_chunk(ddog_TracerPayloadBytes *builder,
                                     uint64_t trace_id_high,
                                     uint64_t trace_id_low);

/**
 * Number of spans already in `chunk`.
 */
uintptr_t ddog_chunk_span_count(const ddog_TraceChunkBytes *chunk);

/**
 * Appends an empty span to `chunk`.
 */
ddog_SpanBytes *ddog_new_span(ddog_TraceChunkBytes *chunk);

/**
 * Appends an empty link to `span`.
 */
ddog_SpanLinkBytes *ddog_new_link(ddog_SpanBytes *span);

/**
 * Appends an empty event to `span`.
 */
ddog_SpanEventBytes *ddog_new_event(ddog_SpanBytes *span);

void ddog_span_set_id(ddog_SpanBytes *span, uint64_t value);

void ddog_span_set_parent_id(ddog_SpanBytes *span, uint64_t value);

void ddog_span_set_start(ddog_SpanBytes *span, int64_t value);

void ddog_span_set_duration(ddog_SpanBytes *span, int64_t value);

void ddog_span_set_error(ddog_SpanBytes *span, bool error);

/**
 * Reads the span error flag (e.g. to mirror it onto an inferred span).
 */
bool ddog_span_get_error(const ddog_SpanBytes *span);

void ddog_set_span_service(ddog_SpanBytes *span, ddog_CharSlice value);

void ddog_set_span_name(ddog_SpanBytes *span, ddog_CharSlice value);

void ddog_set_span_resource(ddog_SpanBytes *span, ddog_CharSlice value);

void ddog_set_span_type(ddog_SpanBytes *span, ddog_CharSlice value);

void ddog_set_span_env(ddog_SpanBytes *span, ddog_CharSlice value);

void ddog_set_span_version(ddog_SpanBytes *span, ddog_CharSlice value);

void ddog_set_span_component(ddog_SpanBytes *span, ddog_CharSlice value);

/**
 * Sets the span kind from an OTEL wire value (unset/unknown → Unspecified).
 */
void ddog_set_span_kind(ddog_SpanBytes *span, uint32_t kind);

void ddog_set_chunk_origin(ddog_TraceChunkBytes *chunk, ddog_CharSlice origin);

void ddog_set_chunk_sampling_priority(ddog_TraceChunkBytes *chunk, int32_t priority);

void ddog_set_chunk_sampling_mechanism(ddog_TraceChunkBytes *chunk, uint32_t mechanism);

void ddog_link_set_trace_id(ddog_SpanLinkBytes *link,
                            uint64_t trace_id_high,
                            uint64_t trace_id_low);

void ddog_link_set_span_id(ddog_SpanLinkBytes *link, uint64_t value);

void ddog_link_set_tracestate(ddog_SpanLinkBytes *link, ddog_CharSlice value);

void ddog_event_set_name(ddog_SpanEventBytes *event, ddog_CharSlice value);

void ddog_event_set_time(ddog_SpanEventBytes *event, uint64_t time_unix_nano);

/**
 * The payload's attribute map.
 *
 * # Safety
 * `builder` must be a live builder from [`ddog_v1_new_builder`].
 */
ddog_Attributes *ddog_payload_get_attributes(ddog_TracerPayloadBytes *builder);

/**
 * # Safety
 * `chunk` must be a live pointer from [`ddog_new_chunk`] or [`ddog_v1_get_chunk`].
 */
ddog_Attributes *ddog_chunk_get_attributes(ddog_TraceChunkBytes *chunk);

/**
 * # Safety
 * `span` must be a live pointer from [`ddog_new_span`] or [`ddog_v1_get_span`].
 */
ddog_Attributes *ddog_span_get_attributes(ddog_SpanBytes *span);

/**
 * # Safety
 * `link` must be a live pointer from [`ddog_new_link`] or [`ddog_v1_get_link`].
 */
ddog_Attributes *ddog_link_get_attributes(ddog_SpanLinkBytes *link);

/**
 * # Safety
 * `event` must be a live pointer from [`ddog_new_event`] or [`ddog_v1_get_event`].
 */
ddog_Attributes *ddog_event_get_attributes(ddog_SpanEventBytes *event);

void ddog_attributes_add_str(ddog_Attributes *attrs, ddog_CharSlice key, ddog_CharSlice value);

/**
 * String attribute under a static C literal key (not copied).
 *
 * # Safety
 * `key` must be a NUL-terminated string that lives for the rest of the program.
 */
void ddog_attributes_add_lit(ddog_Attributes *attrs, const char *key, ddog_CharSlice value);

void ddog_attributes_add_int(ddog_Attributes *attrs, ddog_CharSlice key, int64_t value);

void ddog_attributes_add_double(ddog_Attributes *attrs, ddog_CharSlice key, double value);

/**
 * Double attribute under a static C literal key (not copied).
 *
 * # Safety
 * See [`ddog_attributes_add_lit`].
 */
void ddog_attributes_add_double_lit(ddog_Attributes *attrs, const char *key, double value);

void ddog_attributes_add_bool(ddog_Attributes *attrs, ddog_CharSlice key, bool value);

/**
 * Bytes attribute (v0.4 `meta_struct`), copied verbatim.
 */
void ddog_attributes_add_bytes(ddog_Attributes *attrs, ddog_CharSlice key, ddog_CharSlice value);

/**
 * Sets `attrs[key]` to `list`, which is consumed.
 */
void ddog_attributes_add_list(ddog_Attributes *attrs,
                              ddog_CharSlice key,
                              struct ddog_AttrList *list);

/**
 * Sets `attrs[key]` to `map`, which is consumed (it must not be the map `attrs` belongs to).
 */
void ddog_attributes_add_map(ddog_Attributes *attrs, ddog_CharSlice key, struct ddog_AttrMap *map);

/**
 * Copies the attribute `key` from `from_span` onto `to_span`, returning whether the source had it;
 * removes it from the source when `delete_source` is set. Type-preserving.
 *
 * # Safety
 * `from_span`/`to_span` must be distinct; `key` a static NUL-terminated string.
 */
bool ddog_transfer_span_attr(ddog_SpanBytes *from_span,
                             ddog_SpanBytes *to_span,
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
 */
ddog_Attributes *ddog_attr_map_get_attributes(struct ddog_AttrMap *map);

void ddog_attr_list_push_str(struct ddog_AttrList *list, ddog_CharSlice value);

void ddog_attr_list_push_int(struct ddog_AttrList *list, int64_t value);

void ddog_attr_list_push_double(struct ddog_AttrList *list, double value);

void ddog_attr_list_push_bool(struct ddog_AttrList *list, bool value);

void ddog_attr_list_push_bytes(struct ddog_AttrList *list, ddog_CharSlice value);

/**
 * Pushes `child`, which is consumed.
 */
void ddog_attr_list_push_list(struct ddog_AttrList *list, struct ddog_AttrList *child);

/**
 * Pushes `child`, which is consumed.
 */
void ddog_attr_list_push_map(struct ddog_AttrList *list, struct ddog_AttrMap *child);

ddog_CharSlice ddog_v1_get_payload_env(const ddog_TracerPayloadBytes *builder);

ddog_CharSlice ddog_v1_get_payload_app_version(const ddog_TracerPayloadBytes *builder);

ddog_CharSlice ddog_v1_get_payload_hostname(const ddog_TracerPayloadBytes *builder);

/**
 * Number of chunks in the builder.
 */
uintptr_t ddog_v1_get_chunk_count(const ddog_TracerPayloadBytes *builder);

/**
 * Chunk `idx` (null if out of range).
 */
ddog_TraceChunkBytes *ddog_v1_get_chunk(ddog_TracerPayloadBytes *builder, uintptr_t idx);

/**
 * Span `idx` of `chunk` (null if out of range), e.g. to re-fetch a span after a sibling push.
 */
ddog_SpanBytes *ddog_v1_get_span(ddog_TraceChunkBytes *chunk, uintptr_t idx);

uintptr_t ddog_v1_get_link_count(const ddog_SpanBytes *span);

/**
 * Link `idx` of `span` (null if out of range).
 */
ddog_SpanLinkBytes *ddog_v1_get_link(ddog_SpanBytes *span, uintptr_t idx);

uintptr_t ddog_v1_get_event_count(const ddog_SpanBytes *span);

/**
 * Event `idx` of `span` (null if out of range).
 */
ddog_SpanEventBytes *ddog_v1_get_event(ddog_SpanBytes *span, uintptr_t idx);

uint64_t ddog_v1_get_chunk_trace_id_high(const ddog_TraceChunkBytes *chunk);

uint64_t ddog_v1_get_chunk_trace_id_low(const ddog_TraceChunkBytes *chunk);

/**
 * Reads the chunk sampling priority; returns `false` (and leaves `out` untouched) when unset.
 */
bool ddog_v1_get_chunk_sampling_priority(const ddog_TraceChunkBytes *chunk, int32_t *out);

/**
 * Reads the chunk sampling mechanism; returns `false` (and leaves `out` untouched) when unset.
 */
bool ddog_v1_get_chunk_sampling_mechanism(const ddog_TraceChunkBytes *chunk, uint32_t *out);

ddog_CharSlice ddog_v1_get_chunk_origin(const ddog_TraceChunkBytes *chunk);

ddog_CharSlice ddog_v1_get_span_service(const ddog_SpanBytes *span);

ddog_CharSlice ddog_v1_get_span_name(const ddog_SpanBytes *span);

ddog_CharSlice ddog_v1_get_span_resource(const ddog_SpanBytes *span);

ddog_CharSlice ddog_v1_get_span_type(const ddog_SpanBytes *span);

ddog_CharSlice ddog_v1_get_span_env(const ddog_SpanBytes *span);

ddog_CharSlice ddog_v1_get_span_version(const ddog_SpanBytes *span);

ddog_CharSlice ddog_v1_get_span_component(const ddog_SpanBytes *span);

uint64_t ddog_v1_get_span_id(const ddog_SpanBytes *span);

uint64_t ddog_v1_get_span_parent_id(const ddog_SpanBytes *span);

int64_t ddog_v1_get_span_start(const ddog_SpanBytes *span);

int64_t ddog_v1_get_span_duration(const ddog_SpanBytes *span);

bool ddog_v1_get_span_error(const ddog_SpanBytes *span);

/**
 * The span kind as its OTEL wire value.
 */
uint32_t ddog_v1_get_span_kind(const ddog_SpanBytes *span);

uint64_t ddog_v1_get_link_trace_id_high(const ddog_SpanLinkBytes *link);

uint64_t ddog_v1_get_link_trace_id_low(const ddog_SpanLinkBytes *link);

uint64_t ddog_v1_get_link_span_id(const ddog_SpanLinkBytes *link);

ddog_CharSlice ddog_v1_get_link_tracestate(const ddog_SpanLinkBytes *link);

uint64_t ddog_v1_get_event_time(const ddog_SpanEventBytes *event);

ddog_CharSlice ddog_v1_get_event_name(const ddog_SpanEventBytes *event);

/**
 * Number of entries in `attrs`, including not-yet-deduped repeated keys (the last one wins).
 */
uintptr_t ddog_v1_attributes_len(const ddog_Attributes *attrs);

/**
 * Key of the entry at `idx` (empty if out of range).
 */
ddog_CharSlice ddog_v1_attributes_key(const ddog_Attributes *attrs, uintptr_t idx);

/**
 * Value of the entry at `idx` (null if out of range).
 */
const ddog_AttrValue *ddog_v1_attributes_value(const ddog_Attributes *attrs, uintptr_t idx);

/**
 * [`DDOG_V1_ATTR_*`] type tag of `value`.
 */
uint32_t ddog_v1_value_type(const ddog_AttrValue *value);

/**
 * The string (empty unless a `String`).
 */
ddog_CharSlice ddog_v1_value_str(const ddog_AttrValue *value);

/**
 * The bytes (empty unless `Bytes`).
 */
ddog_CharSlice ddog_v1_value_bytes(const ddog_AttrValue *value);

/**
 * The integer (0 unless an `Int`).
 */
int64_t ddog_v1_value_int(const ddog_AttrValue *value);

/**
 * The double (0.0 unless a `Float`).
 */
double ddog_v1_value_double(const ddog_AttrValue *value);

/**
 * The boolean (false unless a true `Bool`).
 */
bool ddog_v1_value_bool(const ddog_AttrValue *value);

/**
 * Number of elements (0 unless a `List`).
 */
uintptr_t ddog_v1_value_list_len(const ddog_AttrValue *value);

/**
 * Element `idx` (null unless a `List` with that element).
 */
const ddog_AttrValue *ddog_v1_value_list_get(const ddog_AttrValue *value, uintptr_t idx);

/**
 * The members of a `KeyValue`, read with the `ddog_v1_attributes_*` family (null otherwise).
 */
const ddog_Attributes *ddog_v1_value_map(const ddog_AttrValue *value);

/**
 * Renders a span for a tracer's debug log. The returned owned slice must be freed with
 * [`ddog_free_charslice`].
 */
ddog_CharSlice ddog_v1_span_debug_log(const ddog_TraceChunkBytes *chunk,
                                      const ddog_SpanBytes *span);

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
