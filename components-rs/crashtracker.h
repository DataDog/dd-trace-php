// Copyright 2021-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0


#ifndef DDOG_CRASHTRACKER_H
#define DDOG_CRASHTRACKER_H

#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include "common.h"

#if defined(_WIN32) && defined(_CRASHTRACKING_COLLECTOR)
#include <werapi.h>
#include <windows.h>
#endif



/**
 * Default value for the timeout field in milliseconds.
 */
#define ddog_Endpoint_DEFAULT_TIMEOUT 3000

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
  DDOG_CRASHT_STACKTRACE_COLLECTION_ENABLED_WITH_INPROCESS_SYMBOLS,
  DDOG_CRASHT_STACKTRACE_COLLECTION_ENABLED_WITH_SYMBOLS_IN_RECEIVER,
} ddog_crasht_StacktraceCollection;

typedef struct ddog_crasht_CrashInfo ddog_crasht_CrashInfo;

typedef struct ddog_crasht_CrashInfoBuilder ddog_crasht_CrashInfoBuilder;

typedef struct ddog_Endpoint ddog_Endpoint;

typedef struct ddog_crasht_StackFrame ddog_crasht_StackFrame;

typedef struct ddog_crasht_StackTrace ddog_crasht_StackTrace;

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
      /**
       * Do not use the value of Ok. This value only exists to overcome
       * Rust -> C code generation.
       */
      bool ok;
    };
    struct {
      struct ddog_Error err;
    };
  };
} ddog_VoidResult;

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
  bool use_alt_stack;
  /**
   * The endpoint to send the crash report to (can be a file://).
   * If None, the crashtracker will infer the agent host from env variables.
   */
  const struct ddog_Endpoint *endpoint;
  enum ddog_crasht_StacktraceCollection resolve_frames;
  /**
   * The set of signals we should be registered for.
   * If empty, use the default set.
   */
  struct ddog_crasht_Slice_I32 signals;
  /**
   * Timeout in milliseconds before the signal handler starts tearing things down to return.
   * This is given as a uint32_t, but the actual timeout needs to fit inside of an i32 (max
   * 2^31-1). This is a limitation of the various interfaces used to guarantee the timeout.
   */
  uint32_t timeout_ms;
  /**
   * Optional filename for a unix domain socket if the receiver is used asynchonously
   */
  ddog_CharSlice optional_unix_socket_filename;
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

/**
 * Holds the raw parts of a Rust Vec; it should only be created from Rust,
 * never from C.
 */
typedef struct ddog_Vec_Tag {
  const struct ddog_Tag *ptr;
  uintptr_t len;
  uintptr_t capacity;
} ddog_Vec_Tag;

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

/**
 * A wrapper for returning owned strings from FFI
 */
typedef struct ddog_StringWrapper {
  /**
   * This is a String stuffed into the vec.
   */
  struct ddog_Vec_U8 message;
} ddog_StringWrapper;

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

#ifdef __cplusplus
extern "C" {
#endif // __cplusplus

/**
 * Cleans up after the crash-tracker:
 * Unregister the crash handler, restore the previous handler (if any), and
 * shut down the receiver.  Note that the use of this function is optional:
 * the receiver will automatically shutdown when the pipe is closed on program
 * exit.
 *
 * # Preconditions
 *   This function assumes that the crashtracker has previously been
 *   initialized.
 * # Safety
 *   Crash-tracking functions are not reentrant.
 *   No other crash-handler functions should be called concurrently.
 * # Atomicity
 *   This function is not atomic. A crash during its execution may lead to
 *   unexpected crash-handling behaviour.
 */
DDOG_CHECK_RETURN struct ddog_VoidResult ddog_crasht_shutdown(void);

/**
 * Reinitialize the crash-tracking infrastructure after a fork.
 * This should be one of the first things done after a fork, to minimize the
 * chance that a crash occurs between the fork, and this call.
 * In particular, reset the counters that track the profiler state machine.
 * NOTE: An alternative design would be to have a 1:many sidecar listening on a
 * socket instead of 1:1 receiver listening on a pipe, but the only real
 * advantage would be to have fewer processes in `ps -a`.
 *
 * # Preconditions
 *   This function assumes that the crash-tracker has previously been
 *   initialized.
 * # Safety
 *   Crash-tracking functions are not reentrant.
 *   No other crash-handler functions should be called concurrently.
 * # Atomicity
 *   This function is not atomic. A crash during its execution may lead to
 *   unexpected crash-handling behaviour.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_update_on_fork(struct ddog_crasht_Config config,
                                                  struct ddog_crasht_ReceiverConfig receiver_config,
                                                  struct ddog_crasht_Metadata metadata);

/**
 * Initialize the crash-tracking infrastructure.
 *
 * # Preconditions
 *   None.
 * # Safety
 *   Crash-tracking functions are not reentrant.
 *   No other crash-handler functions should be called concurrently.
 * # Atomicity
 *   This function is not atomic. A crash during its execution may lead to
 *   unexpected crash-handling behaviour.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_init(struct ddog_crasht_Config config,
                                        struct ddog_crasht_ReceiverConfig receiver_config,
                                        struct ddog_crasht_Metadata metadata);

/**
 * Initialize the crash-tracking infrastructure without launching the receiver.
 *
 * # Preconditions
 *   Requires `config` to be given with a `unix_socket_path`, which is normally optional.
 * # Safety
 *   Crash-tracking functions are not reentrant.
 *   No other crash-handler functions should be called concurrently.
 * # Atomicity
 *   This function is not atomic. A crash during its execution may lead to
 *   unexpected crash-handling behaviour.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_init_without_receiver(struct ddog_crasht_Config config,
                                                         struct ddog_crasht_Metadata metadata);

/**
 * Returns a list of signals suitable for use in a crashtracker config.
 */
struct ddog_crasht_Slice_CInt ddog_crasht_default_signals(void);

/**
 * Removes all existing additional tags
 * Expected to be used after a fork, to reset the additional tags on the child
 * ATOMICITY:
 *     This is NOT ATOMIC.
 *     Should only be used when no conflicting updates can occur,
 *     e.g. after a fork but before profiling ops start on the child.
 * # Safety
 * No safety concerns.
 */
DDOG_CHECK_RETURN struct ddog_VoidResult ddog_crasht_clear_additional_tags(void);

/**
 * Atomically registers a string as an additional tag.
 * Useful for tracking what operations were occurring when a crash occurred.
 * The set does not check for duplicates.
 *
 * Returns:
 *   Ok(handle) on success.  The handle is needed to later remove the id;
 *   Err() on failure. The most likely cause of failure is that the underlying set is full.
 *
 * # Safety
 * The string argument must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_crasht_Result_Usize ddog_crasht_insert_additional_tag(ddog_CharSlice s);

/**
 * Atomically removes a completed SpanId.
 * Useful for tracking what operations were occurring when a crash occurred.
 * 0 is reserved for "NoId"
 *
 * Returns:
 *   `Ok` on success.
 *   `Err` on failure.
 *
 * # Safety
 * No safety concerns.
 */
DDOG_CHECK_RETURN struct ddog_VoidResult ddog_crasht_remove_additional_tag(uintptr_t idx);

/**
 * Resets all counters to 0.
 * Expected to be used after a fork, to reset the counters on the child
 * ATOMICITY:
 *     This is NOT ATOMIC.
 *     Should only be used when no conflicting updates can occur,
 *     e.g. after a fork but before profiling ops start on the child.
 * # Safety
 * No safety concerns.
 */
DDOG_CHECK_RETURN struct ddog_VoidResult ddog_crasht_reset_counters(void);

/**
 * Atomically increments the count associated with `op`.
 * Useful for tracking what operations were occuring when a crash occurred.
 *
 * # Safety
 * No safety concerns.
 */
DDOG_CHECK_RETURN struct ddog_VoidResult ddog_crasht_begin_op(enum ddog_crasht_OpTypes op);

/**
 * Atomically decrements the count associated with `op`.
 * Useful for tracking what operations were occuring when a crash occurred.
 *
 * # Safety
 * No safety concerns.
 */
DDOG_CHECK_RETURN struct ddog_VoidResult ddog_crasht_end_op(enum ddog_crasht_OpTypes op);

/**
 * Resets all stored spans to 0.
 * Expected to be used after a fork, to reset the spans on the child
 * ATOMICITY:
 *     This is NOT ATOMIC.
 *     Should only be used when no conflicting updates can occur,
 *     e.g. after a fork but before profiling ops start on the child.
 * # Safety
 * No safety concerns.
 */
DDOG_CHECK_RETURN struct ddog_VoidResult ddog_crasht_clear_span_ids(void);

/**
 * Resets all stored traces to 0.
 * Expected to be used after a fork, to reset the traces on the child
 * ATOMICITY:
 *     This is NOT ATOMIC.
 *     Should only be used when no conflicting updates can occur,
 *     e.g. after a fork but before profiling ops start on the child.
 * # Safety
 * No safety concerns.
 */
DDOG_CHECK_RETURN struct ddog_VoidResult ddog_crasht_clear_trace_ids(void);

/**
 * Atomically registers an active traceId.
 * Useful for tracking what operations were occurring when a crash occurred.
 * 0 is reserved for "NoId"
 * The set does not check for duplicates.  Adding the same id twice is an error.
 *
 * Inputs:
 * id<high/low>: the 128 bit id, broken into 2 64 bit chunks (see note)
 *
 * Returns:
 *   Ok(handle) on success.  The handle is needed to later remove the id;
 *   Err() on failure. The most likely cause of failure is that the underlying set is full.
 *
 * Note: 128 bit ints in FFI were not stabilized until Rust 1.77
 * https://blog.rust-lang.org/2024/03/30/i128-layout-update.html
 * We're currently locked into 1.76.0, have to do an ugly workaround involving 2 64 bit ints
 * until we can upgrade.
 *
 * # Safety
 * No safety concerns.
 */
DDOG_CHECK_RETURN
struct ddog_crasht_Result_Usize ddog_crasht_insert_trace_id(uint64_t id_high,
                                                            uint64_t id_low);

/**
 * Atomically registers an active SpanId.
 * Useful for tracking what operations were occurring when a crash occurred.
 * 0 is reserved for "NoId".
 * The set does not check for duplicates.  Adding the same id twice is an error.
 *
 * Inputs:
 * id<high/low>: the 128 bit id, broken into 2 64 bit chunks (see note)
 *
 * Returns:
 *   Ok(handle) on success.  The handle is needed to later remove the id;
 *   Err() on failure. The most likely cause of failure is that the underlying set is full.
 *
 * Note: 128 bit ints in FFI were not stabilized until Rust 1.77
 * https://blog.rust-lang.org/2024/03/30/i128-layout-update.html
 * We're currently locked into 1.76.0, have to do an ugly workaround involving 2 64 bit ints
 * until we can upgrade.
 *
 * # Safety
 * No safety concerns.
 */
DDOG_CHECK_RETURN
struct ddog_crasht_Result_Usize ddog_crasht_insert_span_id(uint64_t id_high,
                                                           uint64_t id_low);

/**
 * Atomically removes a completed SpanId.
 * Useful for tracking what operations were occurring when a crash occurred.
 * 0 is reserved for "NoId"
 *
 * Inputs:
 * id<high/low>: the 128 bit id, broken into 2 64 bit chunks (see note)
 * idx: The handle for the id, from a previous successful call to `insert_span_id`.
 *      Attempting to remove the same element twice is an error.
 * Returns:
 *   `Ok` on success.
 *   `Err` on failure.  If `id` is not found at `idx`, `Err` will be returned and the set will not
 *                      be modified.
 *
 * Note: 128 bit ints in FFI were not stabilized until Rust 1.77
 * https://blog.rust-lang.org/2024/03/30/i128-layout-update.html
 * We're currently locked into 1.76.0, have to do an ugly workaround involving 2 64 bit ints
 * until we can upgrade.
 *
 * # Safety
 * No safety concerns.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_remove_span_id(uint64_t id_high,
                                                  uint64_t id_low,
                                                  uintptr_t idx);

/**
 * Atomically removes a completed TraceId.
 * Useful for tracking what operations were occurring when a crash occurred.
 * 0 is reserved for "NoId"
 *
 * Inputs:
 * id<high/low>: the 128 bit id, broken into 2 64 bit chunks (see note)
 * idx: The handle for the id, from a previous successful call to `insert_span_id`.
 *      Attempting to remove the same element twice is an error.
 * Returns:
 *   `Ok` on success.
 *   `Err` on failure.  If `id` is not found at `idx`, `Err` will be returned and the set will not
 *                      be modified.
 *
 * Note: 128 bit ints in FFI were not stabilized until Rust 1.77
 * https://blog.rust-lang.org/2024/03/30/i128-layout-update.html
 * We're currently locked into 1.76.0, have to do an ugly workaround involving 2 64 bit ints
 * until we can upgrade.
 *
 * # Safety
 * No safety concerns.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_remove_trace_id(uint64_t id_high,
                                                   uint64_t id_low,
                                                   uintptr_t idx);

#if (defined(_CRASHTRACKING_COLLECTOR) && defined(_WIN32))
/**
 * Initialize the crash-tracking infrastructure.
 *
 * # Preconditions
 *   None.
 * # Safety
 *   Crash-tracking functions are not reentrant.
 *   No other crash-handler functions should be called concurrently.
 * # Atomicity
 *   This function is not atomic. A crash during its execution may lead to
 *   unexpected crash-handling behaviour.
 */
DDOG_CHECK_RETURN
bool ddog_crasht_init_windows(ddog_CharSlice module,
                              const struct ddog_Endpoint *endpoint,
                              struct ddog_crasht_Metadata metadata);
#endif

#if (defined(_CRASHTRACKING_COLLECTOR) && defined(_WIN32))
HRESULT OutOfProcessExceptionEventSignatureCallback(const void *_context,
                                                    const WER_RUNTIME_EXCEPTION_INFORMATION *_exception_information,
                                                    int32_t _index,
                                                    uint16_t *_name,
                                                    uint32_t *_name_size,
                                                    uint16_t *_value,
                                                    uint32_t *_value_size);
#endif

#if (defined(_CRASHTRACKING_COLLECTOR) && defined(_WIN32))
HRESULT OutOfProcessExceptionEventDebuggerLaunchCallback(const void *_context,
                                                         const WER_RUNTIME_EXCEPTION_INFORMATION *_exception_information,
                                                         BOOL *_is_custom_debugger,
                                                         uint16_t *_debugger_launch,
                                                         uint32_t *_debugger_launch_size,
                                                         BOOL *_is_debugger_auto_launch);
#endif

#if (defined(_CRASHTRACKING_COLLECTOR) && defined(_WIN32))
HRESULT OutOfProcessExceptionEventCallback(const void *context,
                                           const WER_RUNTIME_EXCEPTION_INFORMATION *exception_information,
                                           BOOL *_ownership_claimed,
                                           uint16_t *_event_name,
                                           uint32_t *_size,
                                           uint32_t *_signature_count);
#endif

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Frame
 * made by this module, which has not previously been dropped.
 */
void ddog_crasht_CrashInfo_drop(struct ddog_crasht_Handle_CrashInfo *builder);

/**
 * # Safety
 * The `crash_info` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfo_normalize_ips(struct ddog_crasht_Handle_CrashInfo *crash_info,
                                                           uint32_t pid);

/**
 * # Safety
 * The `crash_info` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfo_resolve_names(struct ddog_crasht_Handle_CrashInfo *crash_info,
                                                           uint32_t pid);

/**
 * # Safety
 * The `crash_info` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * The CharSlice must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfo_upload_to_endpoint(struct ddog_crasht_Handle_CrashInfo *crash_info,
                                                                const struct ddog_Endpoint *endpoint);

/**
 * Create a new CrashInfoBuilder, and returns an opaque reference to it.
 * # Safety
 * No safety issues.
 */
DDOG_CHECK_RETURN
struct  ddog_crasht_CrashInfoBuilder_NewResult ddog_crasht_CrashInfoBuilder_new(void);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Frame
 * made by this module, which has not previously been dropped.
 */
void ddog_crasht_CrashInfoBuilder_drop(struct ddog_crasht_Handle_CrashInfoBuilder *builder);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 */
DDOG_CHECK_RETURN
struct ddog_crasht_CrashInfo_NewResult ddog_crasht_CrashInfoBuilder_build(struct ddog_crasht_Handle_CrashInfoBuilder *builder);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * The CharSlice must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfoBuilder_with_counter(struct ddog_crasht_Handle_CrashInfoBuilder *builder,
                                                                 ddog_CharSlice name,
                                                                 int64_t value);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * The Kind must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfoBuilder_with_kind(struct ddog_crasht_Handle_CrashInfoBuilder *builder,
                                                              enum ddog_crasht_ErrorKind kind);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * The CharSlice must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfoBuilder_with_file(struct ddog_crasht_Handle_CrashInfoBuilder *builder,
                                                              ddog_CharSlice filename);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * The CharSlice must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfoBuilder_with_file_and_contents(struct ddog_crasht_Handle_CrashInfoBuilder *builder,
                                                                           ddog_CharSlice filename,
                                                                           struct ddog_crasht_Slice_CharSlice contents);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * The CharSlice must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfoBuilder_with_fingerprint(struct ddog_crasht_Handle_CrashInfoBuilder *builder,
                                                                     ddog_CharSlice fingerprint);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * The CharSlice must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfoBuilder_with_incomplete(struct ddog_crasht_Handle_CrashInfoBuilder *builder,
                                                                    bool incomplete);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * The CharSlice must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfoBuilder_with_log_message(struct ddog_crasht_Handle_CrashInfoBuilder *builder,
                                                                     ddog_CharSlice message,
                                                                     bool also_print);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * All arguments must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfoBuilder_with_metadata(struct ddog_crasht_Handle_CrashInfoBuilder *builder,
                                                                  struct ddog_crasht_Metadata metadata);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * All arguments must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfoBuilder_with_os_info(struct ddog_crasht_Handle_CrashInfoBuilder *builder,
                                                                 struct ddog_crasht_OsInfo os_info);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * All arguments must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfoBuilder_with_os_info_this_machine(struct ddog_crasht_Handle_CrashInfoBuilder *builder);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * All arguments must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfoBuilder_with_proc_info(struct ddog_crasht_Handle_CrashInfoBuilder *builder,
                                                                   struct ddog_crasht_ProcInfo proc_info);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * All arguments must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfoBuilder_with_sig_info(struct ddog_crasht_Handle_CrashInfoBuilder *builder,
                                                                  struct ddog_crasht_SigInfo sig_info);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * All arguments must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfoBuilder_with_span_id(struct ddog_crasht_Handle_CrashInfoBuilder *builder,
                                                                 struct ddog_crasht_Span span_id);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * All arguments must be valid.
 * Consumes the stack argument.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfoBuilder_with_stack(struct ddog_crasht_Handle_CrashInfoBuilder *builder,
                                                               struct ddog_crasht_Handle_StackTrace *stack);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * All arguments must be valid.
 * Consumes the stack argument.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfoBuilder_with_thread(struct ddog_crasht_Handle_CrashInfoBuilder *builder,
                                                                struct ddog_crasht_ThreadData thread);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * The CharSlice must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfoBuilder_with_timestamp(struct ddog_crasht_Handle_CrashInfoBuilder *builder,
                                                                   struct ddog_Timespec ts);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * The CharSlice must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfoBuilder_with_timestamp_now(struct ddog_crasht_Handle_CrashInfoBuilder *builder);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * All arguments must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfoBuilder_with_trace_id(struct ddog_crasht_Handle_CrashInfoBuilder *builder,
                                                                  struct ddog_crasht_Span trace_id);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * The CharSlice must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfoBuilder_with_uuid(struct ddog_crasht_Handle_CrashInfoBuilder *builder,
                                                              ddog_CharSlice uuid);

/**
 * # Safety
 * The `builder` can be null, but if non-null it must point to a Builder made by this module,
 * which has not previously been dropped.
 * The CharSlice must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_CrashInfoBuilder_with_uuid_random(struct ddog_crasht_Handle_CrashInfoBuilder *builder);

/**
 * Create a new StackFrame, and returns an opaque reference to it.
 * # Safety
 * No safety issues.
 */
DDOG_CHECK_RETURN struct ddog_crasht_StackFrame_NewResult ddog_crasht_StackFrame_new(void);

/**
 * # Safety
 * The `frame` can be null, but if non-null it must point to a Frame
 * made by this module, which has not previously been dropped.
 */
void ddog_crasht_StackFrame_drop(struct ddog_crasht_Handle_StackFrame *frame);

/**
 * # Safety
 * The `frame` can be null, but if non-null it must point to a Frame made by this module,
 * which has not previously been dropped.
 * The CharSlice must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_StackFrame_with_ip(struct ddog_crasht_Handle_StackFrame *frame,
                                                      uintptr_t ip);

/**
 * # Safety
 * The `frame` can be null, but if non-null it must point to a Frame made by this module,
 * which has not previously been dropped.
 * The CharSlice must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_StackFrame_with_module_base_address(struct ddog_crasht_Handle_StackFrame *frame,
                                                                       uintptr_t module_base_address);

/**
 * # Safety
 * The `frame` can be null, but if non-null it must point to a Frame made by this module,
 * which has not previously been dropped.
 * The CharSlice must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_StackFrame_with_sp(struct ddog_crasht_Handle_StackFrame *frame,
                                                      uintptr_t sp);

/**
 * # Safety
 * The `frame` can be null, but if non-null it must point to a Frame made by this module,
 * which has not previously been dropped.
 * The CharSlice must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_StackFrame_with_symbol_address(struct ddog_crasht_Handle_StackFrame *frame,
                                                                  uintptr_t symbol_address);

/**
 * # Safety
 * The `frame` can be null, but if non-null it must point to a Frame made by this module,
 * which has not previously been dropped.
 * The CharSlice must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_StackFrame_with_build_id(struct ddog_crasht_Handle_StackFrame *frame,
                                                            ddog_CharSlice build_id);

/**
 * # Safety
 * The `frame` can be null, but if non-null it must point to a Frame made by this module,
 * which has not previously been dropped.
 * The BuildIdType must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_StackFrame_with_build_id_type(struct ddog_crasht_Handle_StackFrame *frame,
                                                                 enum ddog_crasht_BuildIdType build_id_type);

/**
 * # Safety
 * The `frame` can be null, but if non-null it must point to a Frame made by this module,
 * which has not previously been dropped.
 * The FileType must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_StackFrame_with_file_type(struct ddog_crasht_Handle_StackFrame *frame,
                                                             enum ddog_crasht_FileType file_type);

/**
 * # Safety
 * The `frame` can be null, but if non-null it must point to a Frame made by this module,
 * which has not previously been dropped.
 * The CharSlice must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_StackFrame_with_path(struct ddog_crasht_Handle_StackFrame *frame,
                                                        ddog_CharSlice path);

/**
 * # Safety
 * The `frame` can be null, but if non-null it must point to a Frame made by this module,
 * which has not previously been dropped.
 * The CharSlice must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_StackFrame_with_relative_address(struct ddog_crasht_Handle_StackFrame *frame,
                                                                    uintptr_t relative_address);

/**
 * # Safety
 * The `frame` can be null, but if non-null it must point to a Frame made by this module,
 * which has not previously been dropped.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_StackFrame_with_column(struct ddog_crasht_Handle_StackFrame *frame,
                                                          uint32_t column);

/**
 * # Safety
 * The `frame` can be null, but if non-null it must point to a Frame made by this module,
 * which has not previously been dropped.
 * The CharSlice must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_StackFrame_with_file(struct ddog_crasht_Handle_StackFrame *frame,
                                                        ddog_CharSlice file);

/**
 * # Safety
 * The `frame` can be null, but if non-null it must point to a Frame made by this module,
 * which has not previously been dropped.
 * The CharSlice must be valid.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_StackFrame_with_function(struct ddog_crasht_Handle_StackFrame *frame,
                                                            ddog_CharSlice function);

/**
 * # Safety
 * The `frame` can be null, but if non-null it must point to a Frame made by this module,
 * which has not previously been dropped.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_StackFrame_with_line(struct ddog_crasht_Handle_StackFrame *frame,
                                                        uint32_t line);

/**
 * Create a new StackTrace, and returns an opaque reference to it.
 * # Safety
 * No safety issues.
 */
DDOG_CHECK_RETURN struct  ddog_crasht_StackTrace_NewResult ddog_crasht_StackTrace_new(void);

/**
 * # Safety
 * The `frame` can be null, but if non-null it must point to a Frame
 * made by this module, which has not previously been dropped.
 */
void ddog_crasht_StackTrace_drop(struct ddog_crasht_Handle_StackTrace *trace);

/**
 * # Safety
 * The `stacktrace` can be null, but if non-null it must point to a StackTrace made by this module,
 * which has not previously been dropped.
 * The frame can be non-null, but if non-null it must point to a Frame made by this module,
 * which has not previously been dropped.
 * The frame is consumed, and does not need to be dropped after this operation.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_StackTrace_push_frame(struct ddog_crasht_Handle_StackTrace *trace,
                                                         struct ddog_crasht_Handle_StackFrame *frame,
                                                         bool incomplete);

/**
 * # Safety
 * The `stacktrace` can be null, but if non-null it must point to a StackTrace made by this module,
 * which has not previously been dropped.
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_StackTrace_set_complete(struct ddog_crasht_Handle_StackTrace *trace);

/**
 * Demangles the string "name".
 * If demangling fails, returns an empty string ""
 *
 * # Safety
 * `name` should be a valid reference to a utf8 encoded String.
 * The string is copied into the result, and does not need to outlive this call
 */
DDOG_CHECK_RETURN
struct ddog_StringWrapperResult ddog_crasht_demangle(ddog_CharSlice name,
                                                     enum ddog_crasht_DemangleOptions options);

/**
 * Receives data from a crash collector via a pipe on `stdin`, formats it into
 * `CrashInfo` json, and emits it to the endpoint/file defined in `config`.
 *
 * At a high-level, this exists because doing anything in a
 * signal handler is dangerous, so we fork a sidecar to do the stuff we aren't
 * allowed to do in the handler.
 *
 * See comments in [crashtracker/lib.rs] for a full architecture description.
 * # Safety
 * No safety concerns
 */
DDOG_CHECK_RETURN struct ddog_VoidResult ddog_crasht_receiver_entry_point_stdin(void);

/**
 * Receives data from a crash collector via a pipe on `stdin`, formats it into
 * `CrashInfo` json, and emits it to the endpoint/file defined in `config`.
 *
 * At a high-level, this exists because doing anything in a
 * signal handler is dangerous, so we fork a sidecar to do the stuff we aren't
 * allowed to do in the handler.
 *
 * See comments in [profiling/crashtracker/mod.rs] for a full architecture
 * description.
 * # Safety
 * No safety concerns
 */
DDOG_CHECK_RETURN
struct ddog_VoidResult ddog_crasht_receiver_entry_point_unix_socket(ddog_CharSlice socket_path);

#ifdef __cplusplus
}  // extern "C"
#endif  // __cplusplus

#endif  /* DDOG_CRASHTRACKER_H */
