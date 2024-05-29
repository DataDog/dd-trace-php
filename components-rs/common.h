// Copyright 2021-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0


#ifndef DDOG_COMMON_H
#define DDOG_COMMON_H

#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#define DDOG_CHARSLICE_C(string) \
/* NOTE: Compilation fails if you pass in a char* instead of a literal */ ((ddog_CharSlice){ .ptr = "" string, .len = sizeof(string) - 1 })

#define DDOG_CHARSLICE_C_BARE(string) \
/* NOTE: Compilation fails if you pass in a char* instead of a literal */ { .ptr = "" string, .len = sizeof(string) - 1 }

#if defined __GNUC__
#  define DDOG_GNUC_VERSION(major) __GNUC__ >= major
#else
#  define DDOG_GNUC_VERSION(major) (0)
#endif

#if defined __has_attribute
#  define DDOG_HAS_ATTRIBUTE(attribute, major) __has_attribute(attribute)
#else
#  define DDOG_HAS_ATTRIBUTE(attribute, major) DDOG_GNUC_VERSION(major)
#endif

#if defined(__cplusplus) && (__cplusplus >= 201703L)
#  define DDOG_CHECK_RETURN [[nodiscard]]
#elif defined(_Check_return_) /* SAL */
#  define DDOG_CHECK_RETURN _Check_return_
#elif DDOG_HAS_ATTRIBUTE(warn_unused_result, 4)
#  define DDOG_CHECK_RETURN __attribute__((__warn_unused_result__))
#else
#  define DDOG_CHECK_RETURN
#endif

/**
 * Default value for the timeout field in milliseconds.
 */
#define ddog_Endpoint_DEFAULT_TIMEOUT 3000

typedef struct ddog_Endpoint ddog_Endpoint;

typedef struct ddog_Tag ddog_Tag;

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

typedef struct ddog_Slice_CChar {
  /**
   * Must be non-null and suitably aligned for the underlying type.
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

/**
 * A wrapper for returning owned strings from FFI
 */
typedef struct ddog_StringWrapper {
  /**
   * This is a String stuffed into the vec.
   */
  struct ddog_Vec_U8 message;
} ddog_StringWrapper;

/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 */
typedef struct ddog_Vec_Tag {
  const struct ddog_Tag *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_Tag;

typedef enum ddog_Vec_Tag_PushResult_Tag {
  DDOG_VEC_TAG_PUSH_RESULT_OK,
  DDOG_VEC_TAG_PUSH_RESULT_ERR,
} ddog_Vec_Tag_PushResult_Tag;

typedef struct ddog_Vec_Tag_PushResult {
  ddog_Vec_Tag_PushResult_Tag tag;
  union {
    struct {
      struct ddog_Error err;
    };
  };
} ddog_Vec_Tag_PushResult;

typedef struct ddog_Vec_Tag_ParseResult {
  struct ddog_Vec_Tag tags;
  struct ddog_Error *error_message;
} ddog_Vec_Tag_ParseResult;

#define ddog_LOG_ONCE (1 << 3)

#define ddog_MultiTargetFetcher_DEFAULT_CLIENTS_LIMIT 100

typedef enum ddog_ConfigurationOrigin {
  DDOG_CONFIGURATION_ORIGIN_ENV_VAR,
  DDOG_CONFIGURATION_ORIGIN_CODE,
  DDOG_CONFIGURATION_ORIGIN_DD_CONFIG,
  DDOG_CONFIGURATION_ORIGIN_REMOTE_CONFIG,
  DDOG_CONFIGURATION_ORIGIN_DEFAULT,
} ddog_ConfigurationOrigin;

typedef enum ddog_InBodyLocation {
  DDOG_IN_BODY_LOCATION_NONE,
  DDOG_IN_BODY_LOCATION_START,
  DDOG_IN_BODY_LOCATION_END,
} ddog_InBodyLocation;

typedef enum ddog_Log {
  DDOG_LOG_ERROR = 1,
  DDOG_LOG_WARN = 2,
  DDOG_LOG_INFO = 3,
  DDOG_LOG_DEBUG = 4,
  DDOG_LOG_TRACE = 5,
  DDOG_LOG_DEPRECATED = (3 | ddog_LOG_ONCE),
  DDOG_LOG_STARTUP = (3 | (2 << 4)),
  DDOG_LOG_STARTUP_WARN = (1 | (2 << 4)),
  DDOG_LOG_SPAN = (4 | (3 << 4)),
  DDOG_LOG_SPAN_TRACE = (5 | (3 << 4)),
  DDOG_LOG_HOOK_TRACE = (5 | (4 << 4)),
} ddog_Log;

typedef enum ddog_MetricNamespace {
  DDOG_METRIC_NAMESPACE_TRACERS,
  DDOG_METRIC_NAMESPACE_PROFILERS,
  DDOG_METRIC_NAMESPACE_RUM,
  DDOG_METRIC_NAMESPACE_APPSEC,
  DDOG_METRIC_NAMESPACE_IDE_PLUGINS,
  DDOG_METRIC_NAMESPACE_LIVE_DEBUGGER,
  DDOG_METRIC_NAMESPACE_IAST,
  DDOG_METRIC_NAMESPACE_GENERAL,
  DDOG_METRIC_NAMESPACE_TELEMETRY,
  DDOG_METRIC_NAMESPACE_APM,
  DDOG_METRIC_NAMESPACE_SIDECAR,
} ddog_MetricNamespace;

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
} ddog_RemoteConfigCapabilities;

typedef enum ddog_RemoteConfigProduct {
  DDOG_REMOTE_CONFIG_PRODUCT_APM_TRACING,
  DDOG_REMOTE_CONFIG_PRODUCT_LIVE_DEBUGGER,
} ddog_RemoteConfigProduct;

/**
 * `InstanceId` is a structure that holds session and runtime identifiers.
 */
typedef struct ddog_InstanceId ddog_InstanceId;

typedef struct ddog_RemoteConfigState ddog_RemoteConfigState;

typedef struct ddog_SidecarActionsBuffer ddog_SidecarActionsBuffer;

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

/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 */
typedef struct ddog_Vec_CChar {
  const char *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_CChar;

typedef enum ddog_IntermediateValue_Tag {
  DDOG_INTERMEDIATE_VALUE_STRING,
  DDOG_INTERMEDIATE_VALUE_NUMBER,
  DDOG_INTERMEDIATE_VALUE_BOOL,
  DDOG_INTERMEDIATE_VALUE_NULL,
  DDOG_INTERMEDIATE_VALUE_REFERENCED,
} ddog_IntermediateValue_Tag;

typedef struct ddog_IntermediateValue {
  ddog_IntermediateValue_Tag tag;
  union {
    struct {
      ddog_CharSlice string;
    };
    struct {
      double number;
    };
    struct {
      bool bool_;
    };
    struct {
      const void *referenced;
    };
  };
} ddog_IntermediateValue;

typedef struct ddog_VoidCollection {
  intptr_t count;
  const void *elements;
  void (*free)(struct ddog_VoidCollection);
} ddog_VoidCollection;

typedef struct ddog_Evaluator {
  bool (*equals)(const void*, struct ddog_IntermediateValue, struct ddog_IntermediateValue);
  bool (*greater_than)(const void*, struct ddog_IntermediateValue, struct ddog_IntermediateValue);
  bool (*greater_or_equals)(const void*,
                            struct ddog_IntermediateValue,
                            struct ddog_IntermediateValue);
  const void *(*fetch_identifier)(const void*, const ddog_CharSlice*);
  const void *(*fetch_index)(const void*, const void*, struct ddog_IntermediateValue);
  const void *(*fetch_nested)(const void*, const void*, struct ddog_IntermediateValue);
  uint64_t (*length)(const void*, const void*);
  struct ddog_VoidCollection (*try_enumerate)(const void*, const void*);
  struct ddog_VoidCollection (*stringify)(const void*, const void*);
  intptr_t (*convert_index)(const void*, const void*);
} ddog_Evaluator;

typedef enum ddog_Option_CharSlice_Tag {
  DDOG_OPTION_CHAR_SLICE_SOME_CHAR_SLICE,
  DDOG_OPTION_CHAR_SLICE_NONE_CHAR_SLICE,
} ddog_Option_CharSlice_Tag;

typedef struct ddog_Option_CharSlice {
  ddog_Option_CharSlice_Tag tag;
  union {
    struct {
      ddog_CharSlice some;
    };
  };
} ddog_Option_CharSlice;

typedef struct ddog_CharSliceVec {
  const ddog_CharSlice *strings;
  uintptr_t string_count;
} ddog_CharSliceVec;

typedef struct ddog_ProbeTarget {
  struct ddog_Option_CharSlice type_name;
  struct ddog_Option_CharSlice method_name;
  struct ddog_Option_CharSlice source_file;
  struct ddog_Option_CharSlice signature;
  struct ddog_CharSliceVec lines;
  enum ddog_InBodyLocation in_body_location;
} ddog_ProbeTarget;

typedef struct ddog_LiveDebuggerCallbacks {
  int64_t (*set_span_probe)(const struct ddog_ProbeTarget *target);
  void (*remove_span_probe)(int64_t id);
} ddog_LiveDebuggerCallbacks;

typedef struct ddog_LiveDebuggerSetup {
  const struct ddog_Evaluator *evaluator;
  struct ddog_LiveDebuggerCallbacks callbacks;
} ddog_LiveDebuggerSetup;

typedef struct ddog_DebuggerCapture ddog_DebuggerCapture;
typedef struct ddog_DebuggerValue ddog_DebuggerValue;


typedef enum ddog_EvaluateAt {
  DDOG_EVALUATE_AT_ENTRY,
  DDOG_EVALUATE_AT_EXIT,
} ddog_EvaluateAt;

typedef enum ddog_FieldType {
  DDOG_FIELD_TYPE_STATIC,
  DDOG_FIELD_TYPE_ARG,
  DDOG_FIELD_TYPE_LOCAL,
} ddog_FieldType;

typedef enum ddog_MetricKind {
  DDOG_METRIC_KIND_COUNT,
  DDOG_METRIC_KIND_GAUGE,
  DDOG_METRIC_KIND_HISTOGRAM,
  DDOG_METRIC_KIND_DISTRIBUTION,
} ddog_MetricKind;

typedef enum ddog_SpanProbeTarget {
  DDOG_SPAN_PROBE_TARGET_ACTIVE,
  DDOG_SPAN_PROBE_TARGET_ROOT,
} ddog_SpanProbeTarget;

typedef struct ddog_DebuggerPayload_CharSlice ddog_DebuggerPayload_CharSlice;

typedef struct ddog_DslString ddog_DslString;

typedef struct ddog_Entry_CharSlice ddog_Entry_CharSlice;

typedef struct ddog_HashMap_CharSlice__ValueCharSlice ddog_HashMap_CharSlice__ValueCharSlice;

typedef struct ddog_ProbeCondition ddog_ProbeCondition;

typedef struct ddog_ProbeValue ddog_ProbeValue;

typedef struct ddog_Capture {
  uint32_t max_reference_depth;
  uint32_t max_collection_size;
  uint32_t max_length;
  uint32_t max_field_depth;
} ddog_Capture;

typedef struct ddog_MetricProbe {
  enum ddog_MetricKind kind;
  ddog_CharSlice name;
  const struct ddog_ProbeValue *value;
} ddog_MetricProbe;

typedef struct ddog_LogProbe {
  const struct ddog_DslString *segments;
  const struct ddog_ProbeCondition *when;
  const struct ddog_Capture *capture;
  uint32_t sampling_snapshots_per_second;
} ddog_LogProbe;

typedef struct ddog_Tag {
  ddog_CharSlice name;
  const struct ddog_DslString *value;
} ddog_Tag;

typedef struct ddog_SpanProbeDecoration {
  const struct ddog_ProbeCondition *condition;
  const struct ddog_Tag *tags;
  uintptr_t tags_count;
} ddog_SpanProbeDecoration;

typedef struct ddog_SpanDecorationProbe {
  enum ddog_SpanProbeTarget target;
  const struct ddog_SpanProbeDecoration *decorations;
  uintptr_t decorations_count;
} ddog_SpanDecorationProbe;

typedef enum ddog_ProbeType_Tag {
  DDOG_PROBE_TYPE_METRIC,
  DDOG_PROBE_TYPE_LOG,
  DDOG_PROBE_TYPE_SPAN,
  DDOG_PROBE_TYPE_SPAN_DECORATION,
} ddog_ProbeType_Tag;

typedef struct ddog_ProbeType {
  ddog_ProbeType_Tag tag;
  union {
    struct {
      struct ddog_MetricProbe metric;
    };
    struct {
      struct ddog_LogProbe log;
    };
    struct {
      struct ddog_SpanDecorationProbe span_decoration;
    };
  };
} ddog_ProbeType;

typedef struct ddog_Probe {
  ddog_CharSlice id;
  uint64_t version;
  struct ddog_Option_CharSlice language;
  struct ddog_CharSliceVec tags;
  struct ddog_ProbeTarget target;
  enum ddog_EvaluateAt evaluate_at;
  struct ddog_ProbeType probe;
} ddog_Probe;

typedef struct ddog_FilterList {
  struct ddog_CharSliceVec package_prefixes;
  struct ddog_CharSliceVec classes;
} ddog_FilterList;

typedef struct ddog_ServiceConfiguration {
  ddog_CharSlice id;
  struct ddog_FilterList allow;
  struct ddog_FilterList deny;
  uint32_t sampling_snapshots_per_second;
} ddog_ServiceConfiguration;

typedef enum ddog_LiveDebuggingData_Tag {
  DDOG_LIVE_DEBUGGING_DATA_NONE,
  DDOG_LIVE_DEBUGGING_DATA_PROBE,
  DDOG_LIVE_DEBUGGING_DATA_SERVICE_CONFIGURATION,
} ddog_LiveDebuggingData_Tag;

typedef struct ddog_LiveDebuggingData {
  ddog_LiveDebuggingData_Tag tag;
  union {
    struct {
      struct ddog_Probe probe;
    };
    struct {
      struct ddog_ServiceConfiguration service_configuration;
    };
  };
} ddog_LiveDebuggingData;

typedef struct ddog_LiveDebuggingParseResult {
  struct ddog_LiveDebuggingData data;
  struct ddog_LiveDebuggingData *opaque_data;
} ddog_LiveDebuggingParseResult;

/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 */
typedef struct ddog_Vec_DebuggerPayloadCharSlice {
  const struct ddog_DebuggerPayload_CharSlice *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_DebuggerPayloadCharSlice;

typedef struct ddog_HashMap_CharSlice__ValueCharSlice ddog_Fields_CharSlice;

/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 */
typedef struct ddog_Vec_DebuggerValue {
  const ddog_DebuggerValue *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_DebuggerValue;

/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 */
typedef struct ddog_Vec_EntryCharSlice {
  const struct ddog_Entry_CharSlice *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_EntryCharSlice;

typedef struct ddog_CaptureValue {
  ddog_CharSlice type;
  ddog_CharSlice value;
  ddog_Fields_CharSlice *fields;
  struct ddog_Vec_DebuggerValue elements;
  struct ddog_Vec_EntryCharSlice entries;
  bool is_null;
  bool truncated;
  ddog_CharSlice not_captured_reason;
  ddog_CharSlice size;
} ddog_CaptureValue;

typedef enum ddog_LogLevel {
  DDOG_LOG_LEVEL_ERROR,
  DDOG_LOG_LEVEL_WARN,
  DDOG_LOG_LEVEL_DEBUG,
} ddog_LogLevel;

typedef enum ddog_MetricType {
  DDOG_METRIC_TYPE_GAUGE,
  DDOG_METRIC_TYPE_COUNT,
  DDOG_METRIC_TYPE_DISTRIBUTION,
} ddog_MetricType;

typedef enum ddog_TelemetryWorkerBuilderBoolProperty {
  DDOG_TELEMETRY_WORKER_BUILDER_BOOL_PROPERTY_CONFIG_TELEMETRY_DEBUG_LOGGING_ENABLED,
} ddog_TelemetryWorkerBuilderBoolProperty;

typedef enum ddog_TelemetryWorkerBuilderEndpointProperty {
  DDOG_TELEMETRY_WORKER_BUILDER_ENDPOINT_PROPERTY_CONFIG_ENDPOINT,
} ddog_TelemetryWorkerBuilderEndpointProperty;

typedef enum ddog_TelemetryWorkerBuilderStrProperty {
  DDOG_TELEMETRY_WORKER_BUILDER_STR_PROPERTY_APPLICATION_SERVICE_VERSION,
  DDOG_TELEMETRY_WORKER_BUILDER_STR_PROPERTY_APPLICATION_ENV,
  DDOG_TELEMETRY_WORKER_BUILDER_STR_PROPERTY_APPLICATION_RUNTIME_NAME,
  DDOG_TELEMETRY_WORKER_BUILDER_STR_PROPERTY_APPLICATION_RUNTIME_VERSION,
  DDOG_TELEMETRY_WORKER_BUILDER_STR_PROPERTY_APPLICATION_RUNTIME_PATCHES,
  DDOG_TELEMETRY_WORKER_BUILDER_STR_PROPERTY_HOST_CONTAINER_ID,
  DDOG_TELEMETRY_WORKER_BUILDER_STR_PROPERTY_HOST_OS,
  DDOG_TELEMETRY_WORKER_BUILDER_STR_PROPERTY_HOST_KERNEL_NAME,
  DDOG_TELEMETRY_WORKER_BUILDER_STR_PROPERTY_HOST_KERNEL_RELEASE,
  DDOG_TELEMETRY_WORKER_BUILDER_STR_PROPERTY_HOST_KERNEL_VERSION,
  DDOG_TELEMETRY_WORKER_BUILDER_STR_PROPERTY_RUNTIME_ID,
} ddog_TelemetryWorkerBuilderStrProperty;

typedef struct ddog_TelemetryWorkerBuilder ddog_TelemetryWorkerBuilder;

/**
 * TelemetryWorkerHandle is a handle which allows interactions with the telemetry worker.
 * The handle is safe to use across threads.
 *
 * The worker won't send data to the agent until you call `TelemetryWorkerHandle::send_start`
 *
 * To stop the worker, call `TelemetryWorkerHandle::send_stop` which trigger flush asynchronously
 * then `TelemetryWorkerHandle::wait_for_shutdown`
 */
typedef struct ddog_TelemetryWorkerHandle ddog_TelemetryWorkerHandle;

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

typedef struct ddog_ContextKey {
  uint32_t _0;
  enum ddog_MetricType _1;
} ddog_ContextKey;

typedef struct ddog_AgentRemoteConfigReader ddog_AgentRemoteConfigReader;

typedef struct ddog_AgentRemoteConfigWriter_ShmHandle ddog_AgentRemoteConfigWriter_ShmHandle;

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

typedef struct ddog_NativeFile {
  struct ddog_PlatformHandle_File *handle;
} ddog_NativeFile;

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

#ifdef __cplusplus
extern "C" {
#endif // __cplusplus

/**
 * # Safety
 * Only pass null or a valid reference to a `ddog_Error`.
 */
void ddog_Error_drop(struct ddog_Error *error);

/**
 * Returns a CharSlice of the error's message that is valid until the error
 * is dropped.
 * # Safety
 * Only pass null or a valid reference to a `ddog_Error`.
 */
ddog_CharSlice ddog_Error_message(const struct ddog_Error *error);

void ddog_MaybeError_drop(ddog_MaybeError);

DDOG_CHECK_RETURN struct ddog_Endpoint *ddog_endpoint_from_url(ddog_CharSlice url);

DDOG_CHECK_RETURN struct ddog_Endpoint *ddog_endpoint_from_api_key(ddog_CharSlice api_key);

DDOG_CHECK_RETURN
struct ddog_Error *ddog_endpoint_from_api_key_and_site(ddog_CharSlice api_key,
                                                       ddog_CharSlice site,
                                                       struct ddog_Endpoint **endpoint);

void ddog_endpoint_set_timeout(struct ddog_Endpoint *endpoint, uint64_t millis);

void ddog_endpoint_drop(struct ddog_Endpoint*);

/**
 * # Safety
 * Only pass null or a valid reference to a `ddog_StringWrapper`.
 */
void ddog_StringWrapper_drop(struct ddog_StringWrapper *s);

/**
 * Returns a CharSlice of the message that is valid until the StringWrapper
 * is dropped.
 * # Safety
 * Only pass null or a valid reference to a `ddog_StringWrapper`.
 */
ddog_CharSlice ddog_StringWrapper_message(const struct ddog_StringWrapper *s);

DDOG_CHECK_RETURN struct ddog_Vec_Tag ddog_Vec_Tag_new(void);

void ddog_Vec_Tag_drop(struct ddog_Vec_Tag);

/**
 * Creates a new Tag from the provided `key` and `value` by doing a utf8
 * lossy conversion, and pushes into the `vec`. The strings `key` and `value`
 * are cloned to avoid FFI lifetime issues.
 *
 * # Safety
 * The `vec` must be a valid reference.
 * The CharSlices `key` and `value` must point to at least many bytes as their
 * `.len` properties claim.
 */
DDOG_CHECK_RETURN
struct ddog_Vec_Tag_PushResult ddog_Vec_Tag_push(struct ddog_Vec_Tag *vec,
                                                 ddog_CharSlice key,
                                                 ddog_CharSlice value);

/**
 * # Safety
 * The `string`'s .ptr must point to a valid object at least as large as its
 * .len property.
 */
DDOG_CHECK_RETURN struct ddog_Vec_Tag_ParseResult ddog_Vec_Tag_parse(ddog_CharSlice string);

#ifdef __cplusplus
} // extern "C"
#endif // __cplusplus

#endif /* DDOG_COMMON_H */
