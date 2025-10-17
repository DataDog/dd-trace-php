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

typedef struct ddog_ArrayQueue {
  struct ddog_ArrayQueue *inner;
  void (*item_delete_fn)(void*);
} ddog_ArrayQueue;

typedef enum ddog_ArrayQueue_NewResult_Tag {
  DDOG_ARRAY_QUEUE_NEW_RESULT_OK,
  DDOG_ARRAY_QUEUE_NEW_RESULT_ERR,
} ddog_ArrayQueue_NewResult_Tag;

typedef struct ddog_ArrayQueue_NewResult {
  ddog_ArrayQueue_NewResult_Tag tag;
  union {
    struct {
      struct ddog_ArrayQueue *ok;
    };
    struct {
      struct ddog_Error err;
    };
  };
} ddog_ArrayQueue_NewResult;

/**
 * Data structure for the result of the push() and force_push() functions.
 * force_push() replaces the oldest element if the queue is full, while push() returns the given
 * value if the queue is full. For push(), it's redundant to return the value since the caller
 * already has it, but it's returned for consistency with crossbeam API and with force_push().
 */
typedef enum ddog_ArrayQueue_PushResult_Tag {
  DDOG_ARRAY_QUEUE_PUSH_RESULT_OK,
  DDOG_ARRAY_QUEUE_PUSH_RESULT_FULL,
  DDOG_ARRAY_QUEUE_PUSH_RESULT_ERR,
} ddog_ArrayQueue_PushResult_Tag;

typedef struct ddog_ArrayQueue_PushResult {
  ddog_ArrayQueue_PushResult_Tag tag;
  union {
    struct {
      void *full;
    };
    struct {
      struct ddog_Error err;
    };
  };
} ddog_ArrayQueue_PushResult;

typedef enum ddog_ArrayQueue_PopResult_Tag {
  DDOG_ARRAY_QUEUE_POP_RESULT_OK,
  DDOG_ARRAY_QUEUE_POP_RESULT_EMPTY,
  DDOG_ARRAY_QUEUE_POP_RESULT_ERR,
} ddog_ArrayQueue_PopResult_Tag;

typedef struct ddog_ArrayQueue_PopResult {
  ddog_ArrayQueue_PopResult_Tag tag;
  union {
    struct {
      void *ok;
    };
    struct {
      struct ddog_Error err;
    };
  };
} ddog_ArrayQueue_PopResult;

typedef enum ddog_ArrayQueue_BoolResult_Tag {
  DDOG_ARRAY_QUEUE_BOOL_RESULT_OK,
  DDOG_ARRAY_QUEUE_BOOL_RESULT_ERR,
} ddog_ArrayQueue_BoolResult_Tag;

typedef struct ddog_ArrayQueue_BoolResult {
  ddog_ArrayQueue_BoolResult_Tag tag;
  union {
    struct {
      bool ok;
    };
    struct {
      struct ddog_Error err;
    };
  };
} ddog_ArrayQueue_BoolResult;

typedef enum ddog_ArrayQueue_UsizeResult_Tag {
  DDOG_ARRAY_QUEUE_USIZE_RESULT_OK,
  DDOG_ARRAY_QUEUE_USIZE_RESULT_ERR,
} ddog_ArrayQueue_UsizeResult_Tag;

typedef struct ddog_ArrayQueue_UsizeResult {
  ddog_ArrayQueue_UsizeResult_Tag tag;
  union {
    struct {
      uintptr_t ok;
    };
    struct {
      struct ddog_Error err;
    };
  };
} ddog_ArrayQueue_UsizeResult;

typedef enum ddog_Option_U32_Tag {
  DDOG_OPTION_U32_SOME_U32,
  DDOG_OPTION_U32_NONE_U32,
} ddog_Option_U32_Tag;

typedef struct ddog_Option_U32 {
  ddog_Option_U32_Tag tag;
  union {
    struct {
      uint32_t some;
    };
  };
} ddog_Option_U32;

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
  DDOG_CONFIGURATION_ORIGIN_LOCAL_STABLE_CONFIG,
  DDOG_CONFIGURATION_ORIGIN_FLEET_STABLE_CONFIG,
} ddog_ConfigurationOrigin;

typedef enum ddog_EvaluateAt {
  DDOG_EVALUATE_AT_ENTRY,
  DDOG_EVALUATE_AT_EXIT,
} ddog_EvaluateAt;

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

typedef enum ddog_MetricKind {
  DDOG_METRIC_KIND_COUNT,
  DDOG_METRIC_KIND_GAUGE,
  DDOG_METRIC_KIND_HISTOGRAM,
  DDOG_METRIC_KIND_DISTRIBUTION,
} ddog_MetricKind;

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

typedef enum ddog_MetricType {
  DDOG_METRIC_TYPE_GAUGE,
  DDOG_METRIC_TYPE_COUNT,
  DDOG_METRIC_TYPE_DISTRIBUTION,
} ddog_MetricType;

typedef enum ddog_ProbeStatus {
  DDOG_PROBE_STATUS_RECEIVED,
  DDOG_PROBE_STATUS_INSTALLED,
  DDOG_PROBE_STATUS_EMITTING,
  DDOG_PROBE_STATUS_ERROR,
  DDOG_PROBE_STATUS_BLOCKED,
  DDOG_PROBE_STATUS_WARNING,
} ddog_ProbeStatus;

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
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_DD_MULTICONFIG = 42,
  DDOG_REMOTE_CONFIG_CAPABILITIES_ASM_TRACE_TAGGING_RULES = 43,
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

typedef enum ddog_SpanProbeTarget {
  DDOG_SPAN_PROBE_TARGET_ACTIVE,
  DDOG_SPAN_PROBE_TARGET_ROOT,
} ddog_SpanProbeTarget;

typedef struct ddog_DebuggerPayload ddog_DebuggerPayload;

typedef struct ddog_DslString ddog_DslString;

typedef struct ddog_HashMap_ShmCacheKey__ShmCache ddog_HashMap_ShmCacheKey__ShmCache;

/**
 * `InstanceId` is a structure that holds session and runtime identifiers.
 */
typedef struct ddog_InstanceId ddog_InstanceId;

typedef struct ddog_MaybeShmLimiter ddog_MaybeShmLimiter;

typedef struct ddog_ProbeCondition ddog_ProbeCondition;

typedef struct ddog_ProbeValue ddog_ProbeValue;

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
typedef struct ddog_Vec_CharSlice {
  const ddog_CharSlice *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_CharSlice;

typedef struct ddog_Tag {
  ddog_CharSlice name;
  const struct ddog_DslString *value;
} ddog_Tag;

/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 */
typedef struct ddog_Vec_CChar {
  const char *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_CChar;

typedef struct ddog_Vec_CChar *(*ddog_DynamicConfigUpdate)(ddog_CharSlice config,
                                                           ddog_CharSlice value,
                                                           bool return_old);

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
  bool (*equals)(void*, struct ddog_IntermediateValue, struct ddog_IntermediateValue);
  bool (*greater_than)(void*, struct ddog_IntermediateValue, struct ddog_IntermediateValue);
  bool (*greater_or_equals)(void*, struct ddog_IntermediateValue, struct ddog_IntermediateValue);
  const void *(*fetch_identifier)(void*, const ddog_CharSlice*);
  const void *(*fetch_index)(void*, const void*, struct ddog_IntermediateValue);
  const void *(*fetch_nested)(void*, const void*, struct ddog_IntermediateValue);
  uintptr_t (*length)(void*, const void*);
  struct ddog_VoidCollection (*try_enumerate)(void*, const void*);
  ddog_CharSlice (*stringify)(void*, const void*);
  ddog_CharSlice (*get_string)(void*, const void*);
  intptr_t (*convert_index)(void*, const void*);
  bool (*instanceof)(void*, const void*, const ddog_CharSlice*);
} ddog_Evaluator;

typedef struct ddog_CharSliceVec {
  const ddog_CharSlice *strings;
  uintptr_t string_count;
} ddog_CharSliceVec;

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

typedef struct ddog_ProbeTarget {
  ddog_CharSlice type_name;
  ddog_CharSlice method_name;
  ddog_CharSlice source_file;
  struct ddog_Option_CharSlice signature;
  const uint32_t *lines;
  uint32_t lines_count;
  enum ddog_InBodyLocation in_body_location;
} ddog_ProbeTarget;

typedef struct ddog_MetricProbe {
  enum ddog_MetricKind kind;
  ddog_CharSlice name;
  const struct ddog_ProbeValue *value;
} ddog_MetricProbe;

typedef struct ddog_CaptureConfiguration {
  uint32_t max_reference_depth;
  uint32_t max_collection_size;
  uint32_t max_length;
  uint32_t max_field_count;
} ddog_CaptureConfiguration;

typedef struct ddog_LogProbe {
  const struct ddog_DslString *segments;
  const struct ddog_ProbeCondition *when;
  const struct ddog_CaptureConfiguration *capture;
  bool capture_snapshot;
  uint32_t sampling_snapshots_per_second;
} ddog_LogProbe;

typedef struct ddog_SpanProbeTag {
  struct ddog_Tag tag;
  bool next_condition;
} ddog_SpanProbeTag;

typedef struct ddog_SpanDecorationProbe {
  enum ddog_SpanProbeTarget target;
  const struct ddog_ProbeCondition *const *conditions;
  const struct ddog_SpanProbeTag *span_tags;
  uintptr_t span_tags_num;
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
  ddog_CharSlice language;
  struct ddog_CharSliceVec tags;
  struct ddog_ProbeTarget target;
  enum ddog_EvaluateAt evaluate_at;
  struct ddog_ProbeType probe;
  ddog_CharSlice diagnostic_msg;
  enum ddog_ProbeStatus status;
  ddog_CharSlice status_msg;
  ddog_CharSlice status_exception;
  ddog_CharSlice status_stacktrace;
} ddog_Probe;

typedef struct ddog_LiveDebuggerCallbacks {
  int64_t (*set_probe)(struct ddog_Probe probe, const struct ddog_MaybeShmLimiter *limiter);
  void (*remove_probe)(int64_t id);
} ddog_LiveDebuggerCallbacks;

typedef struct ddog_LiveDebuggerSetup {
  const struct ddog_Evaluator *evaluator;
  struct ddog_LiveDebuggerCallbacks callbacks;
} ddog_LiveDebuggerSetup;

/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 */
typedef struct ddog_Vec_DebuggerPayload {
  const struct ddog_DebuggerPayload *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_DebuggerPayload;

/**
 * `QueueId` is a struct that represents a unique identifier for a queue.
 * It contains a single field, `inner`, which is a 64-bit unsigned integer.
 */
typedef uint64_t ddog_QueueId;

typedef struct ddog_HashMap_ShmCacheKey__ShmCache ddog_ShmCacheMap;

/**
 * A 128-bit (16 byte) buffer containing the UUID.
 *
 * # ABI
 *
 * The `Bytes` type is always guaranteed to be have the same ABI as [`Uuid`].
 */
typedef uint8_t ddog_Bytes[16];

/**
 * A Universally Unique Identifier (UUID).
 *
 * # Examples
 *
 * Parse a UUID given in the simple format and print it as a urn:
 *
 * ```
 * # use uuid::Uuid;
 * # fn main() -> Result<(), uuid::Error> {
 * let my_uuid = Uuid::parse_str("a1a2a3a4b1b2c1c2d1d2d3d4d5d6d7d8")?;
 *
 * println!("{}", my_uuid.urn());
 * # Ok(())
 * # }
 * ```
 *
 * Create a new random (V4) UUID and print it out in hexadecimal form:
 *
 * ```
 * // Note that this requires the `v4` feature enabled in the uuid crate.
 * # use uuid::Uuid;
 * # fn main() {
 * # #[cfg(feature = "v4")] {
 * let my_uuid = Uuid::new_v4();
 *
 * println!("{}", my_uuid);
 * # }
 * # }
 * ```
 *
 * # Formatting
 *
 * A UUID can be formatted in one of a few ways:
 *
 * * [`simple`](#method.simple): `a1a2a3a4b1b2c1c2d1d2d3d4d5d6d7d8`.
 * * [`hyphenated`](#method.hyphenated):
 *   `a1a2a3a4-b1b2-c1c2-d1d2-d3d4d5d6d7d8`.
 * * [`urn`](#method.urn): `urn:uuid:A1A2A3A4-B1B2-C1C2-D1D2-D3D4D5D6D7D8`.
 * * [`braced`](#method.braced): `{a1a2a3a4-b1b2-c1c2-d1d2-d3d4d5d6d7d8}`.
 *
 * The default representation when formatting a UUID with `Display` is
 * hyphenated:
 *
 * ```
 * # use uuid::Uuid;
 * # fn main() -> Result<(), uuid::Error> {
 * let my_uuid = Uuid::parse_str("a1a2a3a4b1b2c1c2d1d2d3d4d5d6d7d8")?;
 *
 * assert_eq!(
 *     "a1a2a3a4-b1b2-c1c2-d1d2-d3d4d5d6d7d8",
 *     my_uuid.to_string(),
 * );
 * # Ok(())
 * # }
 * ```
 *
 * Other formats can be specified using adapter methods on the UUID:
 *
 * ```
 * # use uuid::Uuid;
 * # fn main() -> Result<(), uuid::Error> {
 * let my_uuid = Uuid::parse_str("a1a2a3a4b1b2c1c2d1d2d3d4d5d6d7d8")?;
 *
 * assert_eq!(
 *     "urn:uuid:a1a2a3a4-b1b2-c1c2-d1d2-d3d4d5d6d7d8",
 *     my_uuid.urn().to_string(),
 * );
 * # Ok(())
 * # }
 * ```
 *
 * # Endianness
 *
 * The specification for UUIDs encodes the integer fields that make up the
 * value in big-endian order. This crate assumes integer inputs are already in
 * the correct order by default, regardless of the endianness of the
 * environment. Most methods that accept integers have a `_le` variant (such as
 * `from_fields_le`) that assumes any integer values will need to have their
 * bytes flipped, regardless of the endianness of the environment.
 *
 * Most users won't need to worry about endianness unless they need to operate
 * on individual fields (such as when converting between Microsoft GUIDs). The
 * important things to remember are:
 *
 * - The endianness is in terms of the fields of the UUID, not the environment.
 * - The endianness is assumed to be big-endian when there's no `_le` suffix
 *   somewhere.
 * - Byte-flipping in `_le` methods applies to each integer.
 * - Endianness roundtrips, so if you create a UUID with `from_fields_le`
 *   you'll get the same values back out with `to_fields_le`.
 *
 * # ABI
 *
 * The `Uuid` type is always guaranteed to be have the same ABI as [`Bytes`].
 */
typedef ddog_Bytes ddog_Uuid;

/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 */
typedef struct ddog_Vec_RemoteConfigProduct {
  const enum ddog_RemoteConfigProduct *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_RemoteConfigProduct;

typedef struct ddog_Vec_RemoteConfigProduct ddog_VecRemoteConfigProduct;

/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 */
typedef struct ddog_Vec_RemoteConfigCapabilities {
  const enum ddog_RemoteConfigCapabilities *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_RemoteConfigCapabilities;

typedef struct ddog_Vec_RemoteConfigCapabilities ddog_VecRemoteConfigCapabilities;

typedef struct ddog_DebuggerCapture ddog_DebuggerCapture;
typedef struct ddog_DebuggerValue ddog_DebuggerValue;


#define ddog_EVALUATOR_RESULT_UNDEFINED (const void*)0

#define ddog_EVALUATOR_RESULT_INVALID (const void*)-1

#define ddog_EVALUATOR_RESULT_REDACTED (const void*)-2

typedef enum ddog_DebuggerType {
  DDOG_DEBUGGER_TYPE_DIAGNOSTICS,
  DDOG_DEBUGGER_TYPE_LOGS,
} ddog_DebuggerType;

typedef enum ddog_FieldType {
  DDOG_FIELD_TYPE_STATIC,
  DDOG_FIELD_TYPE_ARG,
  DDOG_FIELD_TYPE_LOCAL,
} ddog_FieldType;

typedef struct ddog_Entry ddog_Entry;

typedef struct ddog_HashMap_CowStr__Value ddog_HashMap_CowStr__Value;

typedef struct ddog_InternalIntermediateValue ddog_InternalIntermediateValue;

typedef struct ddog_SenderHandle ddog_SenderHandle;

typedef struct ddog_SnapshotEvaluationError ddog_SnapshotEvaluationError;

typedef struct ddog_String ddog_String;

/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 */
typedef struct ddog_Vec_SnapshotEvaluationError {
  const struct ddog_SnapshotEvaluationError *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_SnapshotEvaluationError;

typedef enum ddog_ConditionEvaluationResult_Tag {
  DDOG_CONDITION_EVALUATION_RESULT_SUCCESS,
  DDOG_CONDITION_EVALUATION_RESULT_FAILURE,
  DDOG_CONDITION_EVALUATION_RESULT_ERROR,
} ddog_ConditionEvaluationResult_Tag;

typedef struct ddog_ConditionEvaluationResult {
  ddog_ConditionEvaluationResult_Tag tag;
  union {
    struct {
      struct ddog_Vec_SnapshotEvaluationError *error;
    };
  };
} ddog_ConditionEvaluationResult;

typedef enum ddog_ValueEvaluationResult_Tag {
  DDOG_VALUE_EVALUATION_RESULT_SUCCESS,
  DDOG_VALUE_EVALUATION_RESULT_ERROR,
} ddog_ValueEvaluationResult_Tag;

typedef struct ddog_ValueEvaluationResult {
  ddog_ValueEvaluationResult_Tag tag;
  union {
    struct {
      struct ddog_InternalIntermediateValue *success;
    };
    struct {
      struct ddog_Vec_SnapshotEvaluationError *error;
    };
  };
} ddog_ValueEvaluationResult;

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

typedef struct ddog_HashMap_CowStr__Value ddog_Fields;

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
typedef struct ddog_Vec_Entry {
  const struct ddog_Entry *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_Entry;

typedef struct ddog_CaptureValue {
  ddog_CharSlice type;
  ddog_CharSlice value;
  ddog_Fields *fields;
  struct ddog_Vec_DebuggerValue elements;
  struct ddog_Vec_Entry entries;
  bool is_null;
  bool truncated;
  ddog_CharSlice not_captured_reason;
  ddog_CharSlice size;
} ddog_CaptureValue;

typedef struct ddog_OwnedCharSlice {
  ddog_CharSlice slice;
  void (*free)(ddog_CharSlice);
} ddog_OwnedCharSlice;

typedef enum ddog_LogLevel {
  DDOG_LOG_LEVEL_ERROR,
  DDOG_LOG_LEVEL_WARN,
  DDOG_LOG_LEVEL_DEBUG,
} ddog_LogLevel;

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

typedef struct ddog_SpanBytes ddog_SpanBytes;
typedef struct ddog_SpanLinkBytes ddog_SpanLinkBytes;
typedef struct ddog_SpanEventBytes ddog_SpanEventBytes;
typedef struct ddog_AttributeAnyValueBytes ddog_AttributeAnyValueBytes;
typedef struct ddog_AttributeArrayValueBytes ddog_AttributeArrayValueBytes;


typedef enum ddog_Method {
  DDOG_METHOD_GET = 0,
  DDOG_METHOD_POST = 1,
  DDOG_METHOD_PUT = 2,
  DDOG_METHOD_DELETE = 3,
  DDOG_METHOD_PATCH = 4,
  DDOG_METHOD_HEAD = 5,
  DDOG_METHOD_OPTIONS = 6,
  DDOG_METHOD_TRACE = 7,
  DDOG_METHOD_CONNECT = 8,
  DDOG_METHOD_OTHER = 9,
} ddog_Method;

typedef struct ddog_AgentInfoReader ddog_AgentInfoReader;

typedef struct ddog_AgentRemoteConfigReader ddog_AgentRemoteConfigReader;

typedef struct ddog_AgentRemoteConfigWriter_ShmHandle ddog_AgentRemoteConfigWriter_ShmHandle;

typedef struct ddog_Arc_Target ddog_Arc_Target;

/**
 * Fundamental configuration of the RC client, which always must be set.
 */
typedef struct ddog_ConfigInvariants ddog_ConfigInvariants;

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

/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 */
typedef struct ddog_Vec_SpanBytes {
  const ddog_SpanBytes *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_SpanBytes;

typedef struct ddog_Vec_SpanBytes ddog_TraceBytes;

/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 */
typedef struct ddog_Vec_TraceBytes {
  const ddog_TraceBytes *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_TraceBytes;

typedef struct ddog_Vec_TraceBytes ddog_TracesBytes;

typedef struct ddog_SenderParameters {
  struct ddog_TracerHeaderTags tracer_headers_tags;
  struct ddog_SidecarTransport *transport;
  struct ddog_InstanceId *instance_id;
  uintptr_t limit;
  int64_t n_requests;
  int64_t buffer_size;
  ddog_CharSlice url;
} ddog_SenderParameters;

typedef enum ddog_crasht_BuildIdType {
  DDOG_CRASHT_BUILD_ID_TYPE_GNU,
  DDOG_CRASHT_BUILD_ID_TYPE_GO,
  DDOG_CRASHT_BUILD_ID_TYPE_PDB,
  DDOG_CRASHT_BUILD_ID_TYPE_SHA1,
} ddog_crasht_BuildIdType;

typedef enum ddog_crasht_DemangleOptions {
  DDOG_CRASHT_DEMANGLE_OPTIONS_COMPLETE,
  DDOG_CRASHT_DEMANGLE_OPTIONS_NAME_ONLY,
} ddog_crasht_DemangleOptions;

typedef enum ddog_crasht_ErrorKind {
  DDOG_CRASHT_ERROR_KIND_PANIC,
  DDOG_CRASHT_ERROR_KIND_UNHANDLED_EXCEPTION,
  DDOG_CRASHT_ERROR_KIND_UNIX_SIGNAL,
} ddog_crasht_ErrorKind;

typedef enum ddog_crasht_FileType {
  DDOG_CRASHT_FILE_TYPE_APK,
  DDOG_CRASHT_FILE_TYPE_ELF,
  DDOG_CRASHT_FILE_TYPE_PE,
} ddog_crasht_FileType;

/**
 * This enum represents operations a the tracked library might be engaged in.
 * Currently only implemented for profiling.
 * The idea is that if a crash consistently occurs while a particular operation
 * is ongoing, its likely related.
 *
 * In the future, we might also track wall-clock time of operations
 * (or some statistical sampling thereof) using the same enum.
 *
 * NOTE: This enum is known to be non-exhaustive.  Feel free to add new types
 *       as needed.
 */
typedef enum ddog_crasht_OpTypes {
  DDOG_CRASHT_OP_TYPES_PROFILER_INACTIVE = 0,
  DDOG_CRASHT_OP_TYPES_PROFILER_COLLECTING_SAMPLE,
  DDOG_CRASHT_OP_TYPES_PROFILER_UNWINDING,
  DDOG_CRASHT_OP_TYPES_PROFILER_SERIALIZING,
  /**
   * Dummy value to allow easier iteration
   */
  DDOG_CRASHT_OP_TYPES_SIZE,
} ddog_crasht_OpTypes;

/**
 * See https://man7.org/linux/man-pages/man2/sigaction.2.html
 * MUST REMAIN IN SYNC WITH THE ENUM IN emit_sigcodes.c
 */
typedef enum ddog_crasht_SiCodes {
  DDOG_CRASHT_SI_CODES_BUS_ADRALN,
  DDOG_CRASHT_SI_CODES_BUS_ADRERR,
  DDOG_CRASHT_SI_CODES_BUS_MCEERR_AO,
  DDOG_CRASHT_SI_CODES_BUS_MCEERR_AR,
  DDOG_CRASHT_SI_CODES_BUS_OBJERR,
  DDOG_CRASHT_SI_CODES_ILL_BADSTK,
  DDOG_CRASHT_SI_CODES_ILL_COPROC,
  DDOG_CRASHT_SI_CODES_ILL_ILLADR,
  DDOG_CRASHT_SI_CODES_ILL_ILLOPC,
  DDOG_CRASHT_SI_CODES_ILL_ILLOPN,
  DDOG_CRASHT_SI_CODES_ILL_ILLTRP,
  DDOG_CRASHT_SI_CODES_ILL_PRVOPC,
  DDOG_CRASHT_SI_CODES_ILL_PRVREG,
  DDOG_CRASHT_SI_CODES_SEGV_ACCERR,
  DDOG_CRASHT_SI_CODES_SEGV_BNDERR,
  DDOG_CRASHT_SI_CODES_SEGV_MAPERR,
  DDOG_CRASHT_SI_CODES_SEGV_PKUERR,
  DDOG_CRASHT_SI_CODES_SI_ASYNCIO,
  DDOG_CRASHT_SI_CODES_SI_KERNEL,
  DDOG_CRASHT_SI_CODES_SI_MESGQ,
  DDOG_CRASHT_SI_CODES_SI_QUEUE,
  DDOG_CRASHT_SI_CODES_SI_SIGIO,
  DDOG_CRASHT_SI_CODES_SI_TIMER,
  DDOG_CRASHT_SI_CODES_SI_TKILL,
  DDOG_CRASHT_SI_CODES_SI_USER,
  DDOG_CRASHT_SI_CODES_SYS_SECCOMP,
  DDOG_CRASHT_SI_CODES_UNKNOWN,
} ddog_crasht_SiCodes;

/**
 * See https://man7.org/linux/man-pages/man7/signal.7.html
 */
typedef enum ddog_crasht_SignalNames {
  DDOG_CRASHT_SIGNAL_NAMES_SIGHUP,
  DDOG_CRASHT_SIGNAL_NAMES_SIGINT,
  DDOG_CRASHT_SIGNAL_NAMES_SIGQUIT,
  DDOG_CRASHT_SIGNAL_NAMES_SIGILL,
  DDOG_CRASHT_SIGNAL_NAMES_SIGTRAP,
  DDOG_CRASHT_SIGNAL_NAMES_SIGABRT,
  DDOG_CRASHT_SIGNAL_NAMES_SIGBUS,
  DDOG_CRASHT_SIGNAL_NAMES_SIGFPE,
  DDOG_CRASHT_SIGNAL_NAMES_SIGKILL,
  DDOG_CRASHT_SIGNAL_NAMES_SIGUSR1,
  DDOG_CRASHT_SIGNAL_NAMES_SIGSEGV,
  DDOG_CRASHT_SIGNAL_NAMES_SIGUSR2,
  DDOG_CRASHT_SIGNAL_NAMES_SIGPIPE,
  DDOG_CRASHT_SIGNAL_NAMES_SIGALRM,
  DDOG_CRASHT_SIGNAL_NAMES_SIGTERM,
  DDOG_CRASHT_SIGNAL_NAMES_SIGCHLD,
  DDOG_CRASHT_SIGNAL_NAMES_SIGCONT,
  DDOG_CRASHT_SIGNAL_NAMES_SIGSTOP,
  DDOG_CRASHT_SIGNAL_NAMES_SIGTSTP,
  DDOG_CRASHT_SIGNAL_NAMES_SIGTTIN,
  DDOG_CRASHT_SIGNAL_NAMES_SIGTTOU,
  DDOG_CRASHT_SIGNAL_NAMES_SIGURG,
  DDOG_CRASHT_SIGNAL_NAMES_SIGXCPU,
  DDOG_CRASHT_SIGNAL_NAMES_SIGXFSZ,
  DDOG_CRASHT_SIGNAL_NAMES_SIGVTALRM,
  DDOG_CRASHT_SIGNAL_NAMES_SIGPROF,
  DDOG_CRASHT_SIGNAL_NAMES_SIGWINCH,
  DDOG_CRASHT_SIGNAL_NAMES_SIGIO,
  DDOG_CRASHT_SIGNAL_NAMES_SIGSYS,
  DDOG_CRASHT_SIGNAL_NAMES_SIGEMT,
  DDOG_CRASHT_SIGNAL_NAMES_SIGINFO,
  DDOG_CRASHT_SIGNAL_NAMES_UNKNOWN,
} ddog_crasht_SignalNames;

/**
 * Stacktrace collection occurs in the context of a crashing process.
 * If the stack is sufficiently corruputed, it is possible (but unlikely),
 * for stack trace collection itself to crash.
 * We recommend fully enabling stacktrace collection, but having an environment
 * variable to allow downgrading the collector.
 */
typedef enum ddog_crasht_StacktraceCollection {
  /**
   * Stacktrace collection occurs in the
   */
  DDOG_CRASHT_STACKTRACE_COLLECTION_DISABLED,
  DDOG_CRASHT_STACKTRACE_COLLECTION_WITHOUT_SYMBOLS,
  /**
   * This option uses `backtrace::resolve_frame_unsynchronized()` to gather symbol information
   * and also unwind inlined functions. Enabling this feature will not only provide symbolic
   * details, but may also yield additional or less stack frames compared to other
   * configurations.
   */
  DDOG_CRASHT_STACKTRACE_COLLECTION_ENABLED_WITH_INPROCESS_SYMBOLS,
  DDOG_CRASHT_STACKTRACE_COLLECTION_ENABLED_WITH_SYMBOLS_IN_RECEIVER,
} ddog_crasht_StacktraceCollection;

typedef struct ddog_crasht_CrashInfo ddog_crasht_CrashInfo;

typedef struct ddog_crasht_CrashInfoBuilder ddog_crasht_CrashInfoBuilder;

typedef struct ddog_crasht_StackFrame ddog_crasht_StackFrame;

typedef struct ddog_crasht_StackTrace ddog_crasht_StackTrace;

/**
 * A generic result type for when an operation may fail,
 * but there's nothing to return in the case of success.
 */
typedef enum ddog_VoidResult_Tag {
  DDOG_VOID_RESULT_OK,
  DDOG_VOID_RESULT_ERR,
} ddog_VoidResult_Tag;

typedef struct ddog_VoidResult {
  ddog_VoidResult_Tag tag;
  union {
    struct {
      struct ddog_Error err;
    };
  };
} ddog_VoidResult;

typedef struct ddog_crasht_Slice_CharSlice {
  /**
   * Should be non-null and suitably aligned for the underlying type. It is
   * allowed but not recommended for the pointer to be null when the len is
   * zero.
   */
  const ddog_CharSlice *ptr;
  /**
   * The number of elements (not bytes) that `.ptr` points to. Must be less
   * than or equal to [isize::MAX].
   */
  uintptr_t len;
} ddog_crasht_Slice_CharSlice;

typedef struct ddog_crasht_Slice_I32 {
  /**
   * Should be non-null and suitably aligned for the underlying type. It is
   * allowed but not recommended for the pointer to be null when the len is
   * zero.
   */
  const int32_t *ptr;
  /**
   * The number of elements (not bytes) that `.ptr` points to. Must be less
   * than or equal to [isize::MAX].
   */
  uintptr_t len;
} ddog_crasht_Slice_I32;

typedef struct ddog_crasht_Config {
  struct ddog_crasht_Slice_CharSlice additional_files;
  bool create_alt_stack;
  bool demangle_names;
  /**
   * The endpoint to send the crash report to (can be a file://).
   * If None, the crashtracker will infer the agent host from env variables.
   */
  const struct ddog_Endpoint *endpoint;
  /**
   * Optional filename for a unix domain socket if the receiver is used asynchonously
   */
  ddog_CharSlice optional_unix_socket_filename;
  enum ddog_crasht_StacktraceCollection resolve_frames;
  /**
   * The set of signals we should be registered for.
   * If empty, use the default set.
   */
  struct ddog_crasht_Slice_I32 signals;
  /**
   * Timeout in milliseconds before the signal handler starts tearing things down to return.
   * If 0, uses the default timeout as specified in
   * `datadog_crashtracker::shared::constants::DD_CRASHTRACK_DEFAULT_TIMEOUT`. Otherwise, uses
   * the specified timeout value.
   * This is given as a uint32_t, but the actual timeout needs to fit inside of an i32 (max
   * 2^31-1). This is a limitation of the various interfaces used to guarantee the timeout.
   */
  uint32_t timeout_ms;
  bool use_alt_stack;
} ddog_crasht_Config;

typedef struct ddog_crasht_EnvVar {
  ddog_CharSlice key;
  ddog_CharSlice val;
} ddog_crasht_EnvVar;

typedef struct ddog_crasht_Slice_EnvVar {
  /**
   * Should be non-null and suitably aligned for the underlying type. It is
   * allowed but not recommended for the pointer to be null when the len is
   * zero.
   */
  const struct ddog_crasht_EnvVar *ptr;
  /**
   * The number of elements (not bytes) that `.ptr` points to. Must be less
   * than or equal to [isize::MAX].
   */
  uintptr_t len;
} ddog_crasht_Slice_EnvVar;

typedef struct ddog_crasht_ReceiverConfig {
  struct ddog_crasht_Slice_CharSlice args;
  struct ddog_crasht_Slice_EnvVar env;
  ddog_CharSlice path_to_receiver_binary;
  /**
   * Optional filename to forward stderr to (useful for logging/debugging)
   */
  ddog_CharSlice optional_stderr_filename;
  /**
   * Optional filename to forward stdout to (useful for logging/debugging)
   */
  ddog_CharSlice optional_stdout_filename;
} ddog_crasht_ReceiverConfig;

typedef struct ddog_crasht_Metadata {
  ddog_CharSlice library_name;
  ddog_CharSlice library_version;
  ddog_CharSlice family;
  /**
   * Should include "service", "environment", etc
   */
  const struct ddog_Vec_Tag *tags;
} ddog_crasht_Metadata;

typedef struct ddog_crasht_Slice_CInt {
  /**
   * Should be non-null and suitably aligned for the underlying type. It is
   * allowed but not recommended for the pointer to be null when the len is
   * zero.
   */
  const int *ptr;
  /**
   * The number of elements (not bytes) that `.ptr` points to. Must be less
   * than or equal to [isize::MAX].
   */
  uintptr_t len;
} ddog_crasht_Slice_CInt;

/**
 * A generic result type for when an operation may fail,
 * or may return <T> in case of success.
 */
typedef enum ddog_crasht_Result_Usize_Tag {
  DDOG_CRASHT_RESULT_USIZE_OK_USIZE,
  DDOG_CRASHT_RESULT_USIZE_ERR_USIZE,
} ddog_crasht_Result_Usize_Tag;

typedef struct ddog_crasht_Result_Usize {
  ddog_crasht_Result_Usize_Tag tag;
  union {
    struct {
      uintptr_t ok;
    };
    struct {
      struct ddog_Error err;
    };
  };
} ddog_crasht_Result_Usize;

/**
 * Represents an object that should only be referred to by its handle.
 * Do not access its member for any reason, only use the C API functions on this struct.
 */
typedef struct ddog_crasht_Handle_CrashInfo {
  struct ddog_crasht_CrashInfo *inner;
} ddog_crasht_Handle_CrashInfo;

/**
 * Represents an object that should only be referred to by its handle.
 * Do not access its member for any reason, only use the C API functions on this struct.
 */
typedef struct ddog_crasht_Handle_CrashInfoBuilder {
  struct ddog_crasht_CrashInfoBuilder *inner;
} ddog_crasht_Handle_CrashInfoBuilder;

typedef enum  ddog_crasht_CrashInfoBuilder_NewResult_Tag {
  DDOG_CRASHT_CRASH_INFO_BUILDER_NEW_RESULT_OK,
  DDOG_CRASHT_CRASH_INFO_BUILDER_NEW_RESULT_ERR,
}  ddog_crasht_CrashInfoBuilder_NewResult_Tag;

typedef struct  ddog_crasht_CrashInfoBuilder_NewResult {
   ddog_crasht_CrashInfoBuilder_NewResult_Tag tag;
  union {
    struct {
      struct ddog_crasht_Handle_CrashInfoBuilder ok;
    };
    struct {
      struct ddog_Error err;
    };
  };
}  ddog_crasht_CrashInfoBuilder_NewResult;

typedef enum ddog_crasht_CrashInfo_NewResult_Tag {
  DDOG_CRASHT_CRASH_INFO_NEW_RESULT_OK,
  DDOG_CRASHT_CRASH_INFO_NEW_RESULT_ERR,
} ddog_crasht_CrashInfo_NewResult_Tag;

typedef struct ddog_crasht_CrashInfo_NewResult {
  ddog_crasht_CrashInfo_NewResult_Tag tag;
  union {
    struct {
      struct ddog_crasht_Handle_CrashInfo ok;
    };
    struct {
      struct ddog_Error err;
    };
  };
} ddog_crasht_CrashInfo_NewResult;

typedef struct ddog_crasht_OsInfo {
  ddog_CharSlice architecture;
  ddog_CharSlice bitness;
  ddog_CharSlice os_type;
  ddog_CharSlice version;
} ddog_crasht_OsInfo;

typedef struct ddog_crasht_ProcInfo {
  uint32_t pid;
} ddog_crasht_ProcInfo;

typedef struct ddog_crasht_SigInfo {
  ddog_CharSlice addr;
  int code;
  enum ddog_crasht_SiCodes code_human_readable;
  int signo;
  enum ddog_crasht_SignalNames signo_human_readable;
} ddog_crasht_SigInfo;

typedef struct ddog_crasht_Span {
  ddog_CharSlice id;
  ddog_CharSlice thread_name;
} ddog_crasht_Span;

/**
 * Represents an object that should only be referred to by its handle.
 * Do not access its member for any reason, only use the C API functions on this struct.
 */
typedef struct ddog_crasht_Handle_StackTrace {
  struct ddog_crasht_StackTrace *inner;
} ddog_crasht_Handle_StackTrace;

typedef struct ddog_crasht_ThreadData {
  bool crashed;
  ddog_CharSlice name;
  struct ddog_crasht_Handle_StackTrace stack;
  ddog_CharSlice state;
} ddog_crasht_ThreadData;

/**
 * Represents time since the Unix Epoch in seconds plus nanoseconds.
 */
typedef struct ddog_Timespec {
  int64_t seconds;
  uint32_t nanoseconds;
} ddog_Timespec;

/**
 * Represents an object that should only be referred to by its handle.
 * Do not access its member for any reason, only use the C API functions on this struct.
 */
typedef struct ddog_crasht_Handle_StackFrame {
  struct ddog_crasht_StackFrame *inner;
} ddog_crasht_Handle_StackFrame;

typedef enum ddog_crasht_StackFrame_NewResult_Tag {
  DDOG_CRASHT_STACK_FRAME_NEW_RESULT_OK,
  DDOG_CRASHT_STACK_FRAME_NEW_RESULT_ERR,
} ddog_crasht_StackFrame_NewResult_Tag;

typedef struct ddog_crasht_StackFrame_NewResult {
  ddog_crasht_StackFrame_NewResult_Tag tag;
  union {
    struct {
      struct ddog_crasht_Handle_StackFrame ok;
    };
    struct {
      struct ddog_Error err;
    };
  };
} ddog_crasht_StackFrame_NewResult;

typedef enum  ddog_crasht_StackTrace_NewResult_Tag {
  DDOG_CRASHT_STACK_TRACE_NEW_RESULT_OK,
  DDOG_CRASHT_STACK_TRACE_NEW_RESULT_ERR,
}  ddog_crasht_StackTrace_NewResult_Tag;

typedef struct  ddog_crasht_StackTrace_NewResult {
   ddog_crasht_StackTrace_NewResult_Tag tag;
  union {
    struct {
      struct ddog_crasht_Handle_StackTrace ok;
    };
    struct {
      struct ddog_Error err;
    };
  };
}  ddog_crasht_StackTrace_NewResult;

typedef enum ddog_StringWrapperResult_Tag {
  DDOG_STRING_WRAPPER_RESULT_OK,
  DDOG_STRING_WRAPPER_RESULT_ERR,
} ddog_StringWrapperResult_Tag;

typedef struct ddog_StringWrapperResult {
  ddog_StringWrapperResult_Tag tag;
  union {
    struct {
      struct ddog_StringWrapper ok;
    };
    struct {
      struct ddog_Error err;
    };
  };
} ddog_StringWrapperResult;

typedef enum ddog_LibraryConfigSource {
  DDOG_LIBRARY_CONFIG_SOURCE_LOCAL_STABLE_CONFIG = 0,
  DDOG_LIBRARY_CONFIG_SOURCE_FLEET_STABLE_CONFIG = 1,
} ddog_LibraryConfigSource;

/**
 * Represents the types of metadata that can be set on a `TracerMetadata` object.
 */
typedef enum ddog_MetadataKind {
  DDOG_METADATA_KIND_RUNTIME_ID = 0,
  DDOG_METADATA_KIND_TRACER_LANGUAGE = 1,
  DDOG_METADATA_KIND_TRACER_VERSION = 2,
  DDOG_METADATA_KIND_HOSTNAME = 3,
  DDOG_METADATA_KIND_SERVICE_NAME = 4,
  DDOG_METADATA_KIND_SERVICE_ENV = 5,
  DDOG_METADATA_KIND_SERVICE_VERSION = 6,
  DDOG_METADATA_KIND_PROCESS_TAGS = 7,
  DDOG_METADATA_KIND_CONTAINER_ID = 8,
} ddog_MetadataKind;

typedef struct ddog_Configurator ddog_Configurator;

/**
 * This struct MUST be backward compatible.
 */
typedef struct ddog_TracerMetadata ddog_TracerMetadata;

/**
 * Ffi safe type representing a borrowed null-terminated C array
 * Equivalent to a std::ffi::CStr
 */
typedef struct ddog_CStr {
  /**
   * Null terminated char array
   */
  char *ptr;
  /**
   * Length of the array, not counting the null-terminator
   */
  uintptr_t length;
} ddog_CStr;

typedef struct ddog_Slice_CharSlice {
  /**
   * Should be non-null and suitably aligned for the underlying type. It is
   * allowed but not recommended for the pointer to be null when the len is
   * zero.
   */
  const ddog_CharSlice *ptr;
  /**
   * The number of elements (not bytes) that `.ptr` points to. Must be less
   * than or equal to [isize::MAX].
   */
  uintptr_t len;
} ddog_Slice_CharSlice;

typedef struct ddog_ProcessInfo {
  struct ddog_Slice_CharSlice args;
  struct ddog_Slice_CharSlice envp;
  ddog_CharSlice language;
} ddog_ProcessInfo;

/**
 * Ffi safe type representing an owned null-terminated C array
 * Equivalent to a std::ffi::CString
 */
typedef struct ddog_CString {
  /**
   * Null terminated char array
   */
  char *ptr;
  /**
   * Length of the array, not counting the null-terminator
   */
  uintptr_t length;
} ddog_CString;

typedef struct ddog_LibraryConfig {
  struct ddog_CString name;
  struct ddog_CString value;
  enum ddog_LibraryConfigSource source;
  struct ddog_CString config_id;
} ddog_LibraryConfig;

/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 */
typedef struct ddog_Vec_LibraryConfig {
  const struct ddog_LibraryConfig *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_LibraryConfig;

/**
 * A result type that includes debug/log messages along with the data
 */
typedef struct ddog_OkResult {
  struct ddog_Vec_LibraryConfig value;
  struct ddog_CString logs;
} ddog_OkResult;

typedef enum ddog_LibraryConfigLoggedResult_Tag {
  DDOG_LIBRARY_CONFIG_LOGGED_RESULT_OK,
  DDOG_LIBRARY_CONFIG_LOGGED_RESULT_ERR,
} ddog_LibraryConfigLoggedResult_Tag;

typedef struct ddog_LibraryConfigLoggedResult {
  ddog_LibraryConfigLoggedResult_Tag tag;
  union {
    struct {
      struct ddog_OkResult ok;
    };
    struct {
      struct ddog_Error err;
    };
  };
} ddog_LibraryConfigLoggedResult;

/**
 * C-compatible representation of an anonymous file handle
 */
typedef struct ddog_TracerMemfdHandle {
  /**
   * File descriptor (relevant only on Linux)
   */
  int fd;
} ddog_TracerMemfdHandle;

/**
 * A generic result type for when an operation may fail,
 * or may return <T> in case of success.
 */
typedef enum ddog_Result_TracerMemfdHandle_Tag {
  DDOG_RESULT_TRACER_MEMFD_HANDLE_OK_TRACER_MEMFD_HANDLE,
  DDOG_RESULT_TRACER_MEMFD_HANDLE_ERR_TRACER_MEMFD_HANDLE,
} ddog_Result_TracerMemfdHandle_Tag;

typedef struct ddog_Result_TracerMemfdHandle {
  ddog_Result_TracerMemfdHandle_Tag tag;
  union {
    struct {
      struct ddog_TracerMemfdHandle ok;
    };
    struct {
      struct ddog_Error err;
    };
  };
} ddog_Result_TracerMemfdHandle;

#ifdef __cplusplus
extern "C" {
#endif // __cplusplus

/**
 * Drops the error. It should not be used after this, though the
 * implementation tries to limit the damage in the case of use-after-free and
 * double-free scenarios.
 *
 * # Safety
 *
 * Only pass null or a pointer to a valid, mutable `ddog_Error`.
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

/**
 * Creates a new ArrayQueue with the given capacity and item_delete_fn.
 * The item_delete_fn is called when an item is dropped from the queue.
 */
DDOG_CHECK_RETURN
struct ddog_ArrayQueue_NewResult ddog_ArrayQueue_new(uintptr_t capacity,
                                                     void (*item_delete_fn)(void*));

/**
 * Drops the ArrayQueue.
 * # Safety
 * The pointer is null or points to a valid memory location allocated by ArrayQueue_new.
 */
void ddog_ArrayQueue_drop(struct ddog_ArrayQueue *queue);

/**
 * Pushes an item into the ArrayQueue. It returns the given value if the queue is full.
 * # Safety
 * The pointer is null or points to a valid memory location allocated by ArrayQueue_new. The value
 * is null or points to a valid memory location that can be deallocated by the item_delete_fn.
 */
struct ddog_ArrayQueue_PushResult ddog_ArrayQueue_push(const struct ddog_ArrayQueue *queue_ptr,
                                                       void *value);

/**
 * Pushes an element into the queue, replacing the oldest element if necessary.
 * # Safety
 * The pointer is null or points to a valid memory location allocated by ArrayQueue_new. The value
 * is null or points to a valid memory location that can be deallocated by the item_delete_fn.
 */
DDOG_CHECK_RETURN
struct ddog_ArrayQueue_PushResult ddog_ArrayQueue_force_push(const struct ddog_ArrayQueue *queue_ptr,
                                                             void *value);

/**
 * Pops an item from the ArrayQueue.
 * # Safety
 * The pointer is null or points to a valid memory location allocated by ArrayQueue_new.
 */
DDOG_CHECK_RETURN
struct ddog_ArrayQueue_PopResult ddog_ArrayQueue_pop(const struct ddog_ArrayQueue *queue_ptr);

/**
 * Checks if the ArrayQueue is empty.
 * # Safety
 * The pointer is null or points to a valid memory location allocated by ArrayQueue_new.
 */
struct ddog_ArrayQueue_BoolResult ddog_ArrayQueue_is_empty(const struct ddog_ArrayQueue *queue_ptr);

/**
 * Returns the length of the ArrayQueue.
 * # Safety
 * The pointer is null or points to a valid memory location allocated by ArrayQueue_new.
 */
struct ddog_ArrayQueue_UsizeResult ddog_ArrayQueue_len(const struct ddog_ArrayQueue *queue_ptr);

/**
 * Returns true if the underlying queue is full.
 * # Safety
 * The pointer is null or points to a valid memory location allocated by ArrayQueue_new.
 */
struct ddog_ArrayQueue_BoolResult ddog_ArrayQueue_is_full(const struct ddog_ArrayQueue *queue_ptr);

/**
 * Returns the capacity of the ArrayQueue.
 * # Safety
 * The pointer is null or points to a valid memory location allocated by ArrayQueue_new.
 */
struct ddog_ArrayQueue_UsizeResult ddog_ArrayQueue_capacity(const struct ddog_ArrayQueue *queue_ptr);

DDOG_CHECK_RETURN struct ddog_Endpoint *ddog_endpoint_from_url(ddog_CharSlice url);

DDOG_CHECK_RETURN struct ddog_Endpoint *ddog_endpoint_from_filename(ddog_CharSlice filename);

DDOG_CHECK_RETURN struct ddog_Endpoint *ddog_endpoint_from_api_key(ddog_CharSlice api_key);

DDOG_CHECK_RETURN
struct ddog_Error *ddog_endpoint_from_api_key_and_site(ddog_CharSlice api_key,
                                                       ddog_CharSlice site,
                                                       struct ddog_Endpoint **endpoint);

void ddog_endpoint_set_timeout(struct ddog_Endpoint *endpoint, uint64_t millis);

void ddog_endpoint_set_test_token(struct ddog_Endpoint *endpoint, ddog_CharSlice token);

void ddog_endpoint_drop(struct ddog_Endpoint*);

struct ddog_Option_U32 ddog_Option_U32_some(uint32_t v);

struct ddog_Option_U32 ddog_Option_U32_none(void);

/**
 * Drops a `ddog_StringWrapper`. It should not be used after this, though the
 * implementation tries to limit the damage in the case of use-after-free and
 * double-free scenarios.
 *
 * # Safety
 *
 * Only pass null or a pointer to a valid, mutable `ddog_StringWrapper`.
 */
void ddog_StringWrapper_drop(struct ddog_StringWrapper *s);

/**
 * Returns a CharSlice of the message.
 *
 * # Safety
 *
 * Only pass null or a valid reference to a `ddog_StringWrapper`.
 * The string should not be mutated nor dropped while the CharSlice is alive.
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
}  // extern "C"
#endif  // __cplusplus

#endif  /* DDOG_COMMON_H */
