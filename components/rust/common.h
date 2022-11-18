// Unless explicitly stated otherwise all files in this repository are licensed under the Apache License Version 2.0.
// This product includes software developed at Datadog (https://www.datadoghq.com/). Copyright 2021-Present Datadog, Inc.


#ifndef DDOG_COMMON_H
#define DDOG_COMMON_H

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#if defined(_MSC_VER)
#define DDOG_CHARSLICE_C(string) \
/* NOTE: Compilation fails if you pass in a char* instead of a literal */ {.ptr = "" string, .len = sizeof(string) - 1}
#else
#define DDOG_CHARSLICE_C(string) \
/* NOTE: Compilation fails if you pass in a char* instead of a literal */ ((ddog_CharSlice){ .ptr = "" string, .len = sizeof(string) - 1 })
#endif

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

typedef struct ddog_Tag ddog_Tag;

/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 * The names ptr and len were chosen to minimize conversion from a previous
 * Buffer type which this has replaced to become more general.
 */
typedef struct ddog_Vec_tag {
  const struct ddog_Tag *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_tag;

/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 * The names ptr and len were chosen to minimize conversion from a previous
 * Buffer type which this has replaced to become more general.
 */
typedef struct ddog_Vec_u8 {
  const uint8_t *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_u8;

typedef enum ddog_PushTagResult_Tag {
  DDOG_PUSH_TAG_RESULT_OK,
  DDOG_PUSH_TAG_RESULT_ERR,
} ddog_PushTagResult_Tag;

typedef struct ddog_PushTagResult {
  ddog_PushTagResult_Tag tag;
  union {
    struct {
      struct ddog_Vec_u8 err;
    };
  };
} ddog_PushTagResult;

/**
 * Remember, the data inside of each member is potentially coming from FFI,
 * so every operation on it is unsafe!
 */
typedef struct ddog_Slice_c_char {
  const char *ptr;
  uintptr_t len;
} ddog_Slice_c_char;

typedef struct ddog_Slice_c_char ddog_CharSlice;

typedef struct ddog_ParseTagsResult {
  struct ddog_Vec_tag tags;
  struct ddog_Vec_u8 *error_message;
} ddog_ParseTagsResult;

typedef struct ddog_TelemetryWorkerHandle ddog_TelemetryWorkerHandle;

typedef enum ddog_LogLevel {
  DDOG_LOG_LEVEL_ERROR,
  DDOG_LOG_LEVEL_WARN,
  DDOG_LOG_LEVEL_DEBUG,
} ddog_LogLevel;

typedef enum ddog_TelemetryWorkerBuilderProperty {
  DDOG_TELEMETRY_WORKER_BUILDER_PROPERTY_APPLICATION_SERVICE_VERSION,
  DDOG_TELEMETRY_WORKER_BUILDER_PROPERTY_APPLICATION_ENV,
  DDOG_TELEMETRY_WORKER_BUILDER_PROPERTY_APPLICATION_RUNTIME_NAME,
  DDOG_TELEMETRY_WORKER_BUILDER_PROPERTY_APPLICATION_RUNTIME_VERSION,
  DDOG_TELEMETRY_WORKER_BUILDER_PROPERTY_APPLICATION_RUNTIME_PATCHES,
  DDOG_TELEMETRY_WORKER_BUILDER_PROPERTY_HOST_CONTAINER_ID,
  DDOG_TELEMETRY_WORKER_BUILDER_PROPERTY_HOST_OS,
  DDOG_TELEMETRY_WORKER_BUILDER_PROPERTY_HOST_KERNEL_NAME,
  DDOG_TELEMETRY_WORKER_BUILDER_PROPERTY_HOST_KERNEL_RELEASE,
  DDOG_TELEMETRY_WORKER_BUILDER_PROPERTY_HOST_KERNEL_VERSION,
  DDOG_TELEMETRY_WORKER_BUILDER_PROPERTY_RUNTIME_ID,
} ddog_TelemetryWorkerBuilderProperty;

typedef struct ddog_NativeFile ddog_NativeFile;

typedef struct ddog_NativeUnixStream ddog_NativeUnixStream;

typedef struct ddog_TelemetryWorkerBuilder ddog_TelemetryWorkerBuilder;

typedef enum ddog_Option_vec_u8_Tag {
  DDOG_OPTION_VEC_U8_SOME_VEC_U8,
  DDOG_OPTION_VEC_U8_NONE_VEC_U8,
} ddog_Option_vec_u8_Tag;

typedef struct ddog_Option_vec_u8 {
  ddog_Option_vec_u8_Tag tag;
  union {
    struct {
      struct ddog_Vec_u8 some;
    };
  };
} ddog_Option_vec_u8;

typedef struct ddog_Option_vec_u8 ddog_MaybeError;

typedef enum ddog_Option_bool_Tag {
  DDOG_OPTION_BOOL_SOME_BOOL,
  DDOG_OPTION_BOOL_NONE_BOOL,
} ddog_Option_bool_Tag;

typedef struct ddog_Option_bool {
  ddog_Option_bool_Tag tag;
  union {
    struct {
      bool some;
    };
  };
} ddog_Option_bool;

typedef struct ddog_Arc_owned_fd ddog_Arc_owned_fd;

typedef struct ddog_BlockingTransport_telemetry_interface_response__telemetry_interface_request ddog_BlockingTransport_telemetry_interface_response__telemetry_interface_request;

typedef struct ddog_InstanceId ddog_InstanceId;

typedef struct ddog_RuntimeMeta ddog_RuntimeMeta;

typedef enum ddog_Option_arc_owned_fd_Tag {
  DDOG_OPTION_ARC_OWNED_FD_SOME_ARC_OWNED_FD,
  DDOG_OPTION_ARC_OWNED_FD_NONE_ARC_OWNED_FD,
} ddog_Option_arc_owned_fd_Tag;

typedef struct ddog_Option_arc_owned_fd {
  ddog_Option_arc_owned_fd_Tag tag;
  union {
    struct {
      struct ddog_Arc_owned_fd some;
    };
  };
} ddog_Option_arc_owned_fd;

/**
 * PlatformHandle contains a valid reference counted FileDescriptor and associated Type information
 * allowing safe transfer and sharing of file handles across processes, and threads
 */
typedef struct ddog_PlatformHandle_file {
  int fd;
  struct ddog_Option_arc_owned_fd inner;
} ddog_PlatformHandle_file;

typedef struct ddog_NativeFile {
  struct ddog_PlatformHandle_file *handle;
} ddog_NativeFile;

/**
 * PlatformHandle contains a valid reference counted FileDescriptor and associated Type information
 * allowing safe transfer and sharing of file handles across processes, and threads
 */
typedef struct ddog_PlatformHandle_unix_stream {
  int fd;
  struct ddog_Option_arc_owned_fd inner;
} ddog_PlatformHandle_unix_stream;

typedef struct ddog_NativeUnixStream {
  struct ddog_PlatformHandle_unix_stream handle;
} ddog_NativeUnixStream;

typedef struct ddog_BlockingTransport_telemetry_interface_response__telemetry_interface_request ddog_TelemetryTransport;

DDOG_CHECK_RETURN struct ddog_Vec_tag ddog_Vec_tag_new(void);

void ddog_Vec_tag_drop(struct ddog_Vec_tag);

void ddog_PushTagResult_drop(struct ddog_PushTagResult);

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
struct ddog_PushTagResult ddog_Vec_tag_push(struct ddog_Vec_tag *vec,
                                            ddog_CharSlice key,
                                            ddog_CharSlice value);

/**
 * # Safety
 * The `string`'s .ptr must point to a valid object at least as large as its
 * .len property.
 */
DDOG_CHECK_RETURN struct ddog_ParseTagsResult ddog_Vec_tag_parse(ddog_CharSlice string);

#endif /* DDOG_COMMON_H */
