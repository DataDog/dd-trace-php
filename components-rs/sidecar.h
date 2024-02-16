// Unless explicitly stated otherwise all files in this repository are licensed under the Apache License Version 2.0.
// This product includes software developed at Datadog (https://www.datadoghq.com/). Copyright 2021-Present Datadog, Inc.


#ifndef DDOG_SIDECAR_H
#define DDOG_SIDECAR_H

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include <stdio.h>
#include "common.h"

typedef uint64_t ddog_QueueId;

/**
 * This creates Rust PlatformHandle<File> from supplied C std FILE object.
 * This method takes the ownership of the underlying filedescriptor.
 *
 * # Safety
 * Caller must ensure the file descriptor associated with FILE pointer is open, and valid
 * Caller must not close the FILE associated filedescriptor after calling this fuction
 */
struct ddog_NativeFile ddog_ph_file_from(FILE *file);

struct ddog_NativeFile *ddog_ph_file_clone(const struct ddog_NativeFile *platform_handle);

void ddog_ph_file_drop(struct ddog_NativeFile ph);

ddog_MaybeError ddog_alloc_anon_shm_handle(uintptr_t size, struct ddog_ShmHandle **handle);

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

void ddog_sidecar_transport_drop(ddog_SidecarTransport*);

/**
 * # Safety
 * Caller must ensure the process is safe to fork, at the time when this method is called
 */
ddog_MaybeError ddog_sidecar_connect(ddog_SidecarTransport **connection);

ddog_MaybeError ddog_sidecar_ping(ddog_SidecarTransport **transport);

struct ddog_InstanceId *ddog_sidecar_instanceId_build(ddog_CharSlice session_id,
                                                      ddog_CharSlice runtime_id);

void ddog_sidecar_instanceId_drop(struct ddog_InstanceId *instance_id);

ddog_QueueId ddog_sidecar_queueId_generate(void);

struct ddog_RuntimeMeta *ddog_sidecar_runtimeMeta_build(ddog_CharSlice language_name,
                                                        ddog_CharSlice language_version,
                                                        ddog_CharSlice tracer_version);

void ddog_sidecar_runtimeMeta_drop(struct ddog_RuntimeMeta *meta);

ddog_MaybeError ddog_sidecar_telemetry_enqueueConfig(ddog_SidecarTransport **transport,
                                                     const struct ddog_InstanceId *instance_id,
                                                     const ddog_QueueId *queue_id,
                                                     ddog_CharSlice config_key,
                                                     ddog_CharSlice config_value,
                                                     enum ddog_ConfigurationOrigin origin);

ddog_MaybeError ddog_sidecar_telemetry_addDependency(ddog_SidecarTransport **transport,
                                                     const struct ddog_InstanceId *instance_id,
                                                     const ddog_QueueId *queue_id,
                                                     ddog_CharSlice dependency_name,
                                                     ddog_CharSlice dependency_version);

ddog_MaybeError ddog_sidecar_telemetry_addIntegration(ddog_SidecarTransport **transport,
                                                      const struct ddog_InstanceId *instance_id,
                                                      const ddog_QueueId *queue_id,
                                                      ddog_CharSlice integration_name,
                                                      ddog_CharSlice integration_version,
                                                      bool integration_enabled);

ddog_MaybeError ddog_sidecar_telemetry_flushServiceData(ddog_SidecarTransport **transport,
                                                        const struct ddog_InstanceId *instance_id,
                                                        const ddog_QueueId *queue_id,
                                                        const struct ddog_RuntimeMeta *runtime_meta,
                                                        ddog_CharSlice service_name,
                                                        ddog_CharSlice env_name);

ddog_MaybeError ddog_sidecar_telemetry_end(ddog_SidecarTransport **transport,
                                           const struct ddog_InstanceId *instance_id,
                                           const ddog_QueueId *queue_id);

bool ddog_sidecar_is_closed(ddog_SidecarTransport **transport);

ddog_MaybeError ddog_sidecar_session_set_config(ddog_SidecarTransport **transport,
                                                ddog_CharSlice session_id,
                                                const struct ddog_Endpoint *endpoint,
                                                uint64_t flush_interval_milliseconds,
                                                uintptr_t force_flush_size,
                                                uintptr_t force_drop_size,
                                                ddog_CharSlice log_level,
                                                ddog_CharSlice log_path);

ddog_MaybeError ddog_sidecar_send_trace_v04_shm(ddog_SidecarTransport **transport,
                                                const struct ddog_InstanceId *instance_id,
                                                struct ddog_ShmHandle *shm_handle,
                                                const struct ddog_TracerHeaderTags *tracer_header_tags);

ddog_MaybeError ddog_sidecar_send_trace_v04_bytes(ddog_SidecarTransport **transport,
                                                  const struct ddog_InstanceId *instance_id,
                                                  ddog_CharSlice data,
                                                  const struct ddog_TracerHeaderTags *tracer_header_tags);

ddog_CharSlice ddog_sidecar_dump(ddog_SidecarTransport **transport);

#endif /* DDOG_SIDECAR_H */
