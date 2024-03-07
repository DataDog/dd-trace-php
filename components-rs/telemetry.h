// Copyright 2021-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0


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
