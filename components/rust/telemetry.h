// Unless explicitly stated otherwise all files in this repository are licensed under the Apache License Version 2.0.
// This product includes software developed at Datadog (https://www.datadoghq.com/). Copyright 2021-Present Datadog, Inc.


#ifndef DDOG_TELEMETRY_H
#define DDOG_TELEMETRY_H

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include <stdio.h>

typedef enum ddog_LogLevel {
  DDOG_LOG_LEVEL_ERROR,
  DDOG_LOG_LEVEL_WARN,
  DDOG_LOG_LEVEL_DEBUG,
} ddog_LogLevel;

typedef struct ddog_InstanceId ddog_InstanceId;

typedef struct ddog_RuntimeMeta ddog_RuntimeMeta;

typedef struct ddog_TelemetryWorkerBuilder ddog_TelemetryWorkerBuilder;

typedef struct ddog_TelemetryWorkerHandle ddog_TelemetryWorkerHandle;

/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 */
typedef struct ddog_Vec_U8 {
  const uint8_t *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_U8;

typedef enum ddog_Option_VecU8_Tag {
  DDOG_OPTION_VEC_U8_SOME_VEC_U8,
  DDOG_OPTION_VEC_U8_NONE_VEC_U8,
} ddog_Option_VecU8_Tag;

typedef struct ddog_Option_VecU8 {
  ddog_Option_VecU8_Tag tag;
  union {
    struct {
      struct ddog_Vec_U8 some;
    };
  };
} ddog_Option_VecU8;

typedef struct ddog_Option_VecU8 ddog_MaybeError;

/**
 * Remember, the data inside of each member is potentially coming from FFI,
 * so every operation on it is unsafe!
 */
typedef struct ddog_Slice_CChar {
  const char *ptr;
  uintptr_t len;
} ddog_Slice_CChar;

typedef struct ddog_Slice_CChar ddog_CharSlice;

typedef struct ddog_NativeFile {
} ddog_NativeFile;

typedef struct ddog_NativeUnixStream {
} ddog_NativeUnixStream;

typedef struct ddog_BlockingTransport ddog_TelemetryTransport;

typedef uint64_t ddog_QueueId;

typedef enum ddog_Option_Bool_Tag {
  DDOG_OPTION_BOOL_SOME_BOOL,
  DDOG_OPTION_BOOL_NONE_BOOL,
} ddog_Option_Bool_Tag;

typedef struct ddog_Option_Bool {
  ddog_Option_Bool_Tag tag;
  union {
    struct {
      bool some;
    };
  };
} ddog_Option_Bool;

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
                                         ddog_CharSlice value);

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

void ddog_ph_unix_stream_drop(struct ddog_NativeUnixStream *ph);

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
                                                     ddog_CharSlice config_value);

ddog_MaybeError ddog_sidecar_telemetry_addDependency(ddog_TelemetryTransport **transport,
                                                     const struct ddog_InstanceId *instance_id,
                                                     const ddog_QueueId *queue_id,
                                                     ddog_CharSlice dependency_name,
                                                     ddog_CharSlice dependency_version);

ddog_MaybeError ddog_sidecar_telemetry_addIntegration(ddog_TelemetryTransport **transport,
                                                      const struct ddog_InstanceId *instance_id,
                                                      const ddog_QueueId *queue_id,
                                                      ddog_CharSlice integration_name,
                                                      ddog_CharSlice integration_version);

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
                                            struct ddog_Option_Bool compatible,
                                            struct ddog_Option_Bool enabled,
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
