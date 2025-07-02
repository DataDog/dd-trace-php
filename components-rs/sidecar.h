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
 * Default value for the timeout field in milliseconds.
 */
#define ddog_Endpoint_DEFAULT_TIMEOUT 3000

#define ddog_MultiTargetFetcher_DEFAULT_CLIENTS_LIMIT 100

typedef enum ddog_ConfigurationOrigin {
  DDOG_CONFIGURATION_ORIGIN_ENV_VAR,
  DDOG_CONFIGURATION_ORIGIN_CODE,
  DDOG_CONFIGURATION_ORIGIN_DD_CONFIG,
  DDOG_CONFIGURATION_ORIGIN_REMOTE_CONFIG,
  DDOG_CONFIGURATION_ORIGIN_DEFAULT,
  DDOG_CONFIGURATION_ORIGIN_LOCAL_STABLE_CONFIG,
  DDOG_CONFIGURATION_ORIGIN_FLEET_STABLE_CONFIG,
} ddog_ConfigurationOrigin;

typedef enum ddog_RemoteConfigCapabilities {
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_ACTIVATION = 1,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_IP_BLOCKING = 2,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_DD_RULES = 3,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_EXCLUSIONS = 4,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_REQUEST_BLOCKING = 5,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_RESPONSE_BLOCKING = 6,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_USER_BLOCKING = 7,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_CUSTOM_RULES = 8,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_CUSTOM_BLOCKING_RESPONSE = 9,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_TRUSTED_IPS = 10,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_API_SECURITY_SAMPLE_RATE = 11,
  DDOG_REMOTE_CONFIG_CAPABILITIES_APM_TRACING_SAMPLE_RATE = 12,
  DDOG_REMOTE_CONFIG_CAPABILITIES_APM_TRACING_LOGS_INJECTION = 13,
  DDOG_REMOTE_CONFIG_CAPABILITIES_APM_TRACING_HTTP_HEADER_TAGS = 14,
  DDOG_REMOTE_CONFIG_CAPABILITIES_APM_TRACING_CUSTOM_TAGS = 15,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_PROCESSOR_OVERRIDES = 16,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_CUSTOM_DATA_SCANNERS = 17,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_EXCLUSION_DATA = 18,
  DDOG_REMOTE_CONFIG_CAPABILITIES_APM_TRACING_ENABLED = 19,
  DDOG_REMOTE_CONFIG_CAPABILITIES_APM_TRACING_DATA_STREAMS_ENABLED = 20,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_RASP_SQLI = 21,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_RASP_LFI = 22,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_RASP_SSRF = 23,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_RASP_SHI = 24,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_RASP_XXE = 25,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_RASP_RCE = 26,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_RASP_NOSQLI = 27,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_RASP_XSS = 28,
  DDOG_REMOTE_CONFIG_CAPABILITIES_APM_TRACING_SAMPLE_RULES = 29,
  DDOG_REMOTE_CONFIG_CAPABILITIES_CSM_ACTIVATION = 30,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_AUTO_USER_INSTRUM_MODE = 31,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_ENDPOINT_FINGERPRINT = 32,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_SESSION_FINGERPRINT = 33,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_NETWORK_FINGERPRINT = 34,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_HEADER_FINGERPRINT = 35,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_TRUNCATION_RULES = 36,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_RASP_CMDI = 37,
  DDOG_REMOTE_CONFIG_CAPABILITIES_APM_TRACING_ENABLE_DYNAMIC_INSTRUMENTATION = 38,
  DDOG_REMOTE_CONFIG_CAPABILITIES_APM_TRACING_ENABLE_EXCEPTION_REPLAY = 39,
  DDOG_REMOTE_CONFIG_CAPABILITIES_APM_TRACING_ENABLE_CODE_ORIGIN = 40,
  DDOG_REMOTE_CONFIG_CAPABILITIES_APM_TRACING_ENABLE_LIVE_DEBUGGING = 41,
} ddog_RemoteConfigCapabilities;

typedef enum ddog_RemoteConfigProduct {
  DDOG_REMOTE_CONFIG_PRODUCT_AGENT_CONFIG,
  DDOG_REMOTE_CONFIG_PRODUCT_AGENT_TASK,
  DDOG_REMOTE_CONFIG_PRODUCT_APM_TRACING,
  DDOG_REMOTE_CONFIG_PRODUCT_ASM,
  DDOG_REMOTE_CONFIG_PRODUCT_ASM_DATA,
  DDOG_REMOTE_CONFIG_PRODUCT_ASM_DD,
  DDOG_REMOTE_CONFIG_PRODUCT_ASM_FEATURES,
  DDOG_REMOTE_CONFIG_PRODUCT_LIVE_DEBUGGER,
} ddog_RemoteConfigProduct;

typedef struct ddog_AgentInfoReader ddog_AgentInfoReader;

typedef struct ddog_AgentRemoteConfigReader ddog_AgentRemoteConfigReader;

typedef struct ddog_AgentRemoteConfigWriter_ShmHandle ddog_AgentRemoteConfigWriter_ShmHandle;

typedef struct ddog_Arc_Target ddog_Arc_Target;

/**
 * Fundamental configuration of the RC client, which always must be set.
 */
typedef struct ddog_ConfigInvariants ddog_ConfigInvariants;

typedef struct ddog_DebuggerPayload ddog_DebuggerPayload;

typedef struct ddog_Endpoint ddog_Endpoint;

/**
 * `InstanceId` is a structure that holds session and runtime identifiers.
 */
typedef struct ddog_InstanceId ddog_InstanceId;

typedef struct ddog_MappedMem_ShmHandle ddog_MappedMem_ShmHandle;

/**
 * PlatformHandle contains a valid reference counted FileDescriptor and associated Type information
 * allowing safe transfer and sharing of file handles across processes, and threads
 */
typedef struct ddog_PlatformHandle_File ddog_PlatformHandle_File;

typedef struct ddog_RemoteConfigReader ddog_RemoteConfigReader;

/**
 * `RuntimeMetadata` is a struct that represents the runtime metadata of a language.
 */
typedef struct ddog_RuntimeMetadata ddog_RuntimeMetadata;

typedef struct ddog_ShmHandle ddog_ShmHandle;

/**
 * `SidecarTransport` is a wrapper around a BlockingTransport struct from the `datadog_ipc` crate
 * that handles transparent reconnection.
 * It is used for sending `SidecarInterfaceRequest` and receiving `SidecarInterfaceResponse`.
 *
 * This transport is used for communication between different parts of the sidecar service.
 * It is a blocking transport, meaning that it will block the current thread until the operation is
 * complete.
 */
typedef struct ddog_SidecarTransport ddog_SidecarTransport;

typedef struct ddog_Tag ddog_Tag;

typedef struct ddog_NativeFile {
  struct ddog_PlatformHandle_File *handle;
} ddog_NativeFile;

/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 */
typedef struct ddog_Vec_U8 {
  const uint8_t *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_U8;

/**
 * Please treat this as opaque; do not reach into it, and especially don't
 * write into it! The most relevant APIs are:
 * * `ddog_Error_message`, to get the message as a slice.
 * * `ddog_Error_drop`.
 */
typedef struct ddog_Error {
  /**
   * This is a String stuffed into the vec.
   */
  struct ddog_Vec_U8 message;
} ddog_Error;

typedef enum ddog_Option_Error_Tag {
  DDOG_OPTION_ERROR_SOME_ERROR,
  DDOG_OPTION_ERROR_NONE_ERROR,
} ddog_Option_Error_Tag;

typedef struct ddog_Option_Error {
  ddog_Option_Error_Tag tag;
  union {
    struct {
      struct ddog_Error some;
    };
  };
} ddog_Option_Error;

typedef struct ddog_Option_Error ddog_MaybeError;

typedef struct ddog_Slice_CChar {
  /**
   * Should be non-null and suitably aligned for the underlying type. It is
   * allowed but not recommended for the pointer to be null when the len is
   * zero.
   */
  const char *ptr;
  /**
   * The number of elements (not bytes) that `.ptr` points to. Must be less
   * than or equal to [isize::MAX].
   */
  uintptr_t len;
} ddog_Slice_CChar;

/**
 * Use to represent strings -- should be valid UTF-8.
 */
typedef struct ddog_Slice_CChar ddog_CharSlice;

/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 */
typedef struct ddog_Vec_Tag {
  const struct ddog_Tag *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_Tag;

/**
 * `QueueId` is a struct that represents a unique identifier for a queue.
 * It contains a single field, `inner`, which is a 64-bit unsigned integer.
 */
typedef uint64_t ddog_QueueId;

typedef struct ddog_TracerHeaderTags {
  ddog_CharSlice lang;
  ddog_CharSlice lang_version;
  ddog_CharSlice lang_interpreter;
  ddog_CharSlice lang_vendor;
  ddog_CharSlice tracer_version;
  ddog_CharSlice container_id;
  bool client_computed_top_level;
  bool client_computed_stats;
} ddog_TracerHeaderTags;

/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 */
typedef struct ddog_Vec_DebuggerPayload {
  const struct ddog_DebuggerPayload *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_DebuggerPayload;

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
 * Registers a service and flushes any queued actions.
 */
ddog_MaybeError ddog_sidecar_telemetry_flushServiceData(struct ddog_SidecarTransport **transport,
                                                        const struct ddog_InstanceId *instance_id,
                                                        const ddog_QueueId *queue_id,
                                                        const struct ddog_RuntimeMetadata *runtime_meta,
                                                        ddog_CharSlice service_name,
                                                        ddog_CharSlice env_name);

/**
 * Enqueues a list of actions to be performed.
 */
ddog_MaybeError ddog_sidecar_lifecycle_end(struct ddog_SidecarTransport **transport,
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
                                                   ddog_DDLogLevel level,
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

ddog_MaybeError ddog_sidecar_set_remote_config_data(struct ddog_SidecarTransport **transport,
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

/**
 * Drops the agent info reader.
 */
void ddog_drop_agent_info_reader(struct ddog_AgentInfoReader*);

#endif  /* DDOG_SIDECAR_H */
