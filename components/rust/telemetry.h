// Unless explicitly stated otherwise all files in this repository are licensed under the Apache License Version 2.0.
// This product includes software developed at Datadog (https://www.datadoghq.com/). Copyright 2021-Present Datadog, Inc.


#ifndef DDOG_TELEMETRY_H
#define DDOG_TELEMETRY_H

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include <stdio.h>
#include "common.h"

typedef void ddog_TelemetryTransport;

typedef uint64_t ddog_QueueId;

void ddog_MaybeError_drop(ddog_MaybeError);

/**
 * # Safety
 * * builder should be a non null pointer to a null pointer to a builder
 */
ddog_MaybeError ddog_builder_instantiate(struct ddog_TelemetryWorkerBuilder **builder,
                                         ddog_CharSlice service_name,
                                         ddog_CharSlice language_name,
                                         ddog_CharSlice language_version,
                                         ddog_CharSlice tracer_version);

/**
 * # Safety
 * * builder should be a non null pointer to a null pointer to a builder
 */
ddog_MaybeError ddog_builder_instantiate_with_hostname(struct ddog_TelemetryWorkerBuilder **builder,
                                                       ddog_CharSlice hostname,
                                                       ddog_CharSlice service_name,
                                                       ddog_CharSlice language_name,
                                                       ddog_CharSlice language_version,
                                                       ddog_CharSlice tracer_version);

ddog_MaybeError ddog_builder_with_native_deps(struct ddog_TelemetryWorkerBuilder *builder,
                                              bool include_native_deps);

ddog_MaybeError ddog_builder_with_rust_shared_lib_deps(struct ddog_TelemetryWorkerBuilder *builder,
                                                       bool include_rust_shared_lib_deps);

ddog_MaybeError ddog_builder_with_config(struct ddog_TelemetryWorkerBuilder *builder,
                                         ddog_CharSlice name,
                                         ddog_CharSlice value,
                                         enum ddog_ConfigurationOrigin origin);

/**
 * # Safety
 * * handle should be a non null pointer to a null pointer
 */
ddog_MaybeError ddog_builder_run(struct ddog_TelemetryWorkerBuilder *builder,
                                 struct ddog_TelemetryWorkerHandle **handle);

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

void ddog_sidecar_transport_drop(ddog_TelemetryTransport *t);

ddog_TelemetryTransport *ddog_sidecar_transport_clone(const ddog_TelemetryTransport *transport);

/**
 * # Safety
 * Caller must ensure the process is safe to fork, at the time when this method is called
 */
ddog_MaybeError ddog_sidecar_connect(ddog_TelemetryTransport **connection);

ddog_MaybeError ddog_sidecar_ping(ddog_TelemetryTransport **transport);

struct ddog_InstanceId *ddog_sidecar_instanceId_build(ddog_CharSlice session_id,
                                                      ddog_CharSlice runtime_id);

void ddog_sidecar_instanceId_drop(struct ddog_InstanceId *instance_id);

ddog_QueueId ddog_sidecar_queueId_generate(void);

struct ddog_RuntimeMeta *ddog_sidecar_runtimeMeta_build(ddog_CharSlice language_name,
                                                        ddog_CharSlice language_version,
                                                        ddog_CharSlice tracer_version);

void ddog_sidecar_runtimeMeta_drop(struct ddog_RuntimeMeta *meta);

ddog_MaybeError ddog_sidecar_telemetry_enqueueConfig(ddog_TelemetryTransport **transport,
                                                     const struct ddog_InstanceId *instance_id,
                                                     const ddog_QueueId *queue_id,
                                                     ddog_CharSlice config_key,
                                                     ddog_CharSlice config_value,
                                                     enum ddog_ConfigurationOrigin origin);

ddog_MaybeError ddog_sidecar_telemetry_addDependency(ddog_TelemetryTransport **transport,
                                                     const struct ddog_InstanceId *instance_id,
                                                     const ddog_QueueId *queue_id,
                                                     ddog_CharSlice dependency_name,
                                                     ddog_CharSlice dependency_version);

ddog_MaybeError ddog_sidecar_telemetry_addIntegration(ddog_TelemetryTransport **transport,
                                                      const struct ddog_InstanceId *instance_id,
                                                      const ddog_QueueId *queue_id,
                                                      ddog_CharSlice integration_name,
                                                      ddog_CharSlice integration_version,
                                                      bool integration_enabled);

ddog_MaybeError ddog_sidecar_telemetry_flushServiceData(ddog_TelemetryTransport **transport,
                                                        const struct ddog_InstanceId *instance_id,
                                                        const ddog_QueueId *queue_id,
                                                        const struct ddog_RuntimeMeta *runtime_meta,
                                                        ddog_CharSlice service_name);

ddog_MaybeError ddog_sidecar_telemetry_end(ddog_TelemetryTransport **transport,
                                           const struct ddog_InstanceId *instance_id,
                                           const ddog_QueueId *queue_id);

ddog_MaybeError ddog_sidecar_session_config_setAgentUrl(ddog_TelemetryTransport **transport,
                                                        ddog_CharSlice session_id,
                                                        ddog_CharSlice agent_url);

ddog_MaybeError ddog_handle_add_dependency(const struct ddog_TelemetryWorkerHandle *handle,
                                           ddog_CharSlice dependency_name,
                                           ddog_CharSlice dependency_version);

ddog_MaybeError ddog_handle_add_integration(const struct ddog_TelemetryWorkerHandle *handle,
                                            ddog_CharSlice dependency_name,
                                            ddog_CharSlice dependency_version,
                                            bool enabled,
                                            struct ddog_Option_Bool compatible,
                                            struct ddog_Option_Bool auto_enabled);

ddog_MaybeError ddog_handle_add_log(const struct ddog_TelemetryWorkerHandle *handle,
                                    ddog_CharSlice indentifier,
                                    ddog_CharSlice message,
                                    enum ddog_LogLevel level,
                                    ddog_CharSlice stack_trace);

ddog_MaybeError ddog_handle_start(const struct ddog_TelemetryWorkerHandle *handle);

struct ddog_TelemetryWorkerHandle *ddog_handle_clone(const struct ddog_TelemetryWorkerHandle *handle);

ddog_MaybeError ddog_handle_stop(const struct ddog_TelemetryWorkerHandle *handle);

void ddog_handle_wait_for_shutdown(struct ddog_TelemetryWorkerHandle *handle);

void ddog_handle_drop(struct ddog_TelemetryWorkerHandle *handle);

#endif /* DDOG_TELEMETRY_H */
