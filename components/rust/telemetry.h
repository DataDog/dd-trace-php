// Unless explicitly stated otherwise all files in this repository are licensed under the Apache License Version 2.0.
// This product includes software developed at Datadog (https://www.datadoghq.com/). Copyright 2021-Present Datadog, Inc.


#ifndef DDOG_TELEMETRY_H
#define DDOG_TELEMETRY_H

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include <stdio.h>
#include "common.h"

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

ddog_MaybeError ddog_builder_with_application_service_version(struct ddog_TelemetryWorkerBuilder *builder,
                                                              ddog_CharSlice param);

ddog_MaybeError ddog_builder_with_application_env(struct ddog_TelemetryWorkerBuilder *builder,
                                                  ddog_CharSlice param);

ddog_MaybeError ddog_builder_with_application_runtime_name(struct ddog_TelemetryWorkerBuilder *builder,
                                                           ddog_CharSlice param);

ddog_MaybeError ddog_builder_with_application_runtime_version(struct ddog_TelemetryWorkerBuilder *builder,
                                                              ddog_CharSlice param);

ddog_MaybeError ddog_builder_with_application_runtime_patches(struct ddog_TelemetryWorkerBuilder *builder,
                                                              ddog_CharSlice param);

ddog_MaybeError ddog_builder_with_host_container_id(struct ddog_TelemetryWorkerBuilder *builder,
                                                    ddog_CharSlice param);

ddog_MaybeError ddog_builder_with_host_os(struct ddog_TelemetryWorkerBuilder *builder,
                                          ddog_CharSlice param);

ddog_MaybeError ddog_builder_with_host_kernel_name(struct ddog_TelemetryWorkerBuilder *builder,
                                                   ddog_CharSlice param);

ddog_MaybeError ddog_builder_with_host_kernel_release(struct ddog_TelemetryWorkerBuilder *builder,
                                                      ddog_CharSlice param);

ddog_MaybeError ddog_builder_with_host_kernel_version(struct ddog_TelemetryWorkerBuilder *builder,
                                                      ddog_CharSlice param);

ddog_MaybeError ddog_builder_with_runtime_id(struct ddog_TelemetryWorkerBuilder *builder,
                                             ddog_CharSlice param);

/**
 * Sets a property from it's string value.
 *
 * # Available properties:
 *
 * * application.service_version
 *
 * * application.env
 *
 * * application.runtime_name
 *
 * * application.runtime_version
 *
 * * application.runtime_patches
 *
 * * host.container_id
 *
 * * host.os
 *
 * * host.kernel_name
 *
 * * host.kernel_release
 *
 * * host.kernel_version
 *
 * * runtime_id
 *
 *
 */
ddog_MaybeError ddog_builder_with_property(struct ddog_TelemetryWorkerBuilder *builder,
                                           enum ddog_TelemetryWorkerBuilderProperty property,
                                           ddog_CharSlice param);

/**
 * Sets a property from it's string value.
 *
 * # Available properties:
 *
 * * application.service_version
 *
 * * application.env
 *
 * * application.runtime_name
 *
 * * application.runtime_version
 *
 * * application.runtime_patches
 *
 * * host.container_id
 *
 * * host.os
 *
 * * host.kernel_name
 *
 * * host.kernel_release
 *
 * * host.kernel_version
 *
 * * runtime_id
 *
 *
 */
ddog_MaybeError ddog_builder_with_str_property(struct ddog_TelemetryWorkerBuilder *builder,
                                               ddog_CharSlice property,
                                               ddog_CharSlice param);

ddog_MaybeError ddog_builder_with_native_deps(struct ddog_TelemetryWorkerBuilder *builder,
                                              bool include_native_deps);

ddog_MaybeError ddog_builder_with_rust_shared_lib_deps(struct ddog_TelemetryWorkerBuilder *builder,
                                                       bool include_rust_shared_lib_deps);

ddog_MaybeError ddog_builder_with_config(struct ddog_TelemetryWorkerBuilder *builder,
                                         ddog_CharSlice name,
                                         ddog_CharSlice value);

/**
 * # Safety
 * * handle should be a non null pointer to a null pointer
 */
ddog_MaybeError ddog_builder_run(struct ddog_TelemetryWorkerBuilder *builder,
                                 struct ddog_TelemetryWorkerHandle **handle);

ddog_MaybeError ddog_handle_add_dependency(const struct ddog_TelemetryWorkerHandle *handle,
                                           ddog_CharSlice dependency_name,
                                           ddog_CharSlice dependency_version);

ddog_MaybeError ddog_handle_add_integration(const struct ddog_TelemetryWorkerHandle *handle,
                                            ddog_CharSlice dependency_name,
                                            ddog_CharSlice dependency_version,
                                            struct ddog_Option_bool compatible,
                                            struct ddog_Option_bool enabled,
                                            struct ddog_Option_bool auto_enabled);

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

struct ddog_NativeFile ddog_ph_file_from(FILE *file);

struct ddog_NativeFile *ddog_ph_file_clone(const struct ddog_NativeFile *platform_handle);

void ddog_ph_file_drop(struct ddog_NativeFile ph);

void ddog_ph_unix_stream_drop(struct ddog_NativeUnixStream *ph);

ddog_MaybeError ddog_sidecar_connect(struct ddog_NativeUnixStream **connection);

#endif /* DDOG_TELEMETRY_H */
