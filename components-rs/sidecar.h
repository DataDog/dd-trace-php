// Copyright 2021-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

#ifndef DDOG_SIDECAR_H
#define DDOG_SIDECAR_H

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include <stdio.h>
#include "common.h"

/**
 * `QueueId` is a struct that represents a unique identifier for a queue.
 * It contains a single field, `inner`, which is a 64-bit unsigned integer.
 */
typedef uint64_t ddog_QueueId;

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
                                                                       const struct ddog_Vec_Tag *tags,
                                                                       const enum ddog_RemoteConfigProduct *remote_config_products,
                                                                       uintptr_t remote_config_products_count,
                                                                       const enum ddog_RemoteConfigCapabilities *remote_config_capabilities,
                                                                       uintptr_t remote_config_capabilities_count);

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

ddog_MaybeError ddog_sidecar_ping(struct ddog_SidecarTransport **transport);

ddog_MaybeError ddog_sidecar_flush_traces(struct ddog_SidecarTransport **transport);

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
                                                     ddog_CharSlice config_id);

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
                                                ddog_CharSlice language,
                                                ddog_CharSlice language_version,
                                                ddog_CharSlice tracer_version,
                                                uint32_t flush_interval_milliseconds,
                                                uint32_t remote_config_poll_interval_millis,
                                                uint32_t telemetry_heartbeat_interval_millis,
                                                uintptr_t force_flush_size,
                                                uintptr_t force_drop_size,
                                                ddog_CharSlice log_level,
                                                ddog_CharSlice log_path,
                                                void *remote_config_notify_function,
                                                const enum ddog_RemoteConfigProduct *remote_config_products,
                                                uintptr_t remote_config_products_count,
                                                const enum ddog_RemoteConfigCapabilities *remote_config_capabilities,
                                                uintptr_t remote_config_capabilities_count,
                                                bool remote_config_enabled,
                                                bool is_fork);

/**
 * Enqueues a telemetry log action to be processed internally.
 * Non-blocking. Logs might be dropped if the internal queue is full.
 *
 * # Safety
 * Pointers must be valid, strings must be null-terminated if not null.
 */
ddog_MaybeError ddog_sidecar_enqueue_telemetry_log(ddog_CharSlice session_id_ffi,
                                                   ddog_CharSlice runtime_id_ffi,
                                                   uint64_t queue_id,
                                                   ddog_CharSlice identifier_ffi,
                                                   enum ddog_LogLevel level,
                                                   ddog_CharSlice message_ffi,
                                                   ddog_CharSlice *stack_trace_ffi,
                                                   ddog_CharSlice *tags_ffi,
                                                   bool is_sensitive);

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

ddog_MaybeError ddog_sidecar_send_debugger_data(struct ddog_SidecarTransport **transport,
                                                const struct ddog_InstanceId *instance_id,
                                                ddog_QueueId queue_id,
                                                struct ddog_Vec_DebuggerPayload payloads);

ddog_MaybeError ddog_sidecar_send_debugger_datum(struct ddog_SidecarTransport **transport,
                                                 const struct ddog_InstanceId *instance_id,
                                                 ddog_QueueId queue_id,
                                                 struct ddog_DebuggerPayload *payload);

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
                                                        const struct ddog_Vec_Tag *global_tags);

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
                                                    ddog_CharSlice session_id,
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
 * Return the path of the crashtracker unix domain socket.
 */
ddog_CharSlice ddog_sidecar_get_crashtracker_unix_socket_path(void);

/**
 * Gets an agent info reader.
 */
struct ddog_AgentInfoReader *ddog_get_agent_info_reader(const struct ddog_Endpoint *endpoint);

/**
 * Gets the current agent info environment (or empty if not existing)
 */
ddog_CharSlice ddog_get_agent_info_env(struct ddog_AgentInfoReader *reader, bool *changed);

void ddog_send_traces_to_sidecar(ddog_TracesBytes *traces,
                                 struct ddog_SenderParameters *parameters);

/**
 * Drops the agent info reader.
 */
void ddog_drop_agent_info_reader(struct ddog_AgentInfoReader*);

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

ddog_CharSlice ddog_serialize_trace_into_charslice(ddog_TraceBytes *trace);

#endif  /* DDOG_SIDECAR_H */
