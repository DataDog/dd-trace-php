// Copyright 2021-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0


#ifndef DDOG_CRASHTRACKER_H
#define DDOG_CRASHTRACKER_H

#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include "common.h"

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
DDOG_CHECK_RETURN struct ddog_crasht_Result ddog_crasht_shutdown(void);

/**
 * Reinitialize the crash-tracking infrastructure after a fork.
 * This should be one of the first things done after a fork, to minimize the
 * chance that a crash occurs between the fork, and this call.
 * In particular, reset the counters that track the profiler state machine,
 * and start a new receiver to collect data from this fork.
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
struct ddog_crasht_Result ddog_crasht_update_on_fork(struct ddog_crasht_Config config,
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
struct ddog_crasht_Result ddog_crasht_init_with_receiver(struct ddog_crasht_Config config,
                                                         struct ddog_crasht_ReceiverConfig receiver_config,
                                                         struct ddog_crasht_Metadata metadata);

/**
 * Initialize the crash-tracking infrastructure, writing to an unix socket in case of crash.
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
struct ddog_crasht_Result ddog_crasht_init_with_unix_socket(struct ddog_crasht_Config config,
                                                            ddog_CharSlice socket_path,
                                                            struct ddog_crasht_Metadata metadata);

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
DDOG_CHECK_RETURN struct ddog_crasht_Result ddog_crasht_reset_counters(void);

/**
 * Atomically increments the count associated with `op`.
 * Useful for tracking what operations were occuring when a crash occurred.
 *
 * # Safety
 * No safety concerns.
 */
DDOG_CHECK_RETURN struct ddog_crasht_Result ddog_crasht_begin_op(enum ddog_crasht_OpTypes op);

/**
 * Atomically decrements the count associated with `op`.
 * Useful for tracking what operations were occuring when a crash occurred.
 *
 * # Safety
 * No safety concerns.
 */
DDOG_CHECK_RETURN struct ddog_crasht_Result ddog_crasht_end_op(enum ddog_crasht_OpTypes op);

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
DDOG_CHECK_RETURN struct ddog_crasht_Result ddog_crasht_clear_span_ids(void);

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
DDOG_CHECK_RETURN struct ddog_crasht_Result ddog_crasht_clear_trace_ids(void);

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
 * We're currently locked into 1.71, have to do an ugly workaround involving 2 64 bit ints
 * until we can upgrade.
 *
 * # Safety
 * No safety concerns.
 */
DDOG_CHECK_RETURN
struct ddog_crasht_UsizeResult ddog_crasht_insert_trace_id(uint64_t id_high,
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
 * We're currently locked into 1.71, have to do an ugly workaround involving 2 64 bit ints
 * until we can upgrade.
 *
 * # Safety
 * No safety concerns.
 */
DDOG_CHECK_RETURN
struct ddog_crasht_UsizeResult ddog_crasht_insert_span_id(uint64_t id_high,
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
 * We're currently locked into 1.71, have to do an ugly workaround involving 2 64 bit ints
 * until we can upgrade.
 *
 * # Safety
 * No safety concerns.
 */
DDOG_CHECK_RETURN
struct ddog_crasht_Result ddog_crasht_remove_span_id(uint64_t id_high,
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
 * We're currently locked into 1.71, have to do an ugly workaround involving 2 64 bit ints
 * until we can upgrade.
 *
 * # Safety
 * No safety concerns.
 */
DDOG_CHECK_RETURN
struct ddog_crasht_Result ddog_crasht_remove_trace_id(uint64_t id_high,
                                                      uint64_t id_low,
                                                      uintptr_t idx);

/**
 * Create a new crashinfo, and returns an opaque reference to it.
 * # Safety
 * No safety issues.
 */
DDOG_CHECK_RETURN struct ddog_crasht_CrashInfoNewResult ddog_crasht_CrashInfo_new(void);

/**
 * # Safety
 * The `crash_info` can be null, but if non-null it must point to a CrashInfo
 * made by this module, which has not previously been dropped.
 */
void ddog_crasht_CrashInfo_drop(struct ddog_crasht_CrashInfo *crashinfo);

/**
 * Best effort attempt to normalize all `ip` on the stacktrace.
 * `pid` must be the pid of the currently active process where the ips came from.
 *
 * # Safety
 * `crashinfo` must be a valid pointer to a `CrashInfo` object.
 */
DDOG_CHECK_RETURN
struct ddog_crasht_Result ddog_crasht_CrashInfo_normalize_ips(struct ddog_crasht_CrashInfo *crashinfo,
                                                              uint32_t pid);

/**
 * Adds a "counter" variable, with the given value.  Useful for determining if
 * "interesting" operations were occurring when the crash did.
 *
 * # Safety
 * `crashinfo` must be a valid pointer to a `CrashInfo` object.
 * `name` should be a valid reference to a utf8 encoded String.
 * The string is copied into the crashinfo, so it does not need to outlive this
 * call.
 */
DDOG_CHECK_RETURN
struct ddog_crasht_Result ddog_crasht_CrashInfo_add_counter(struct ddog_crasht_CrashInfo *crashinfo,
                                                            ddog_CharSlice name,
                                                            int64_t val);

/**
 * Adds the contents of "file" to the crashinfo
 *
 * # Safety
 * `crashinfo` must be a valid pointer to a `CrashInfo` object.
 * `name` should be a valid reference to a utf8 encoded String.
 * The string is copied into the crashinfo, so it does not need to outlive this
 * call.
 */
DDOG_CHECK_RETURN
struct ddog_crasht_Result ddog_crasht_CrashInfo_add_file(struct ddog_crasht_CrashInfo *crashinfo,
                                                         ddog_CharSlice filename);

/**
 * Adds the tag with given "key" and "value" to the crashinfo
 *
 * # Safety
 * `crashinfo` must be a valid pointer to a `CrashInfo` object.
 * `key` should be a valid reference to a utf8 encoded String.
 * `value` should be a valid reference to a utf8 encoded String.
 * The string is copied into the crashinfo, so it does not need to outlive this
 * call.
 */
DDOG_CHECK_RETURN
struct ddog_crasht_Result ddog_crasht_CrashInfo_add_tag(struct ddog_crasht_CrashInfo *crashinfo,
                                                        ddog_CharSlice key,
                                                        ddog_CharSlice value);

/**
 * Sets the crashinfo metadata
 *
 * # Safety
 * `crashinfo` must be a valid pointer to a `CrashInfo` object.
 * All references inside `metadata` must be valid.
 * Strings are copied into the crashinfo, and do not need to outlive this call.
 */
DDOG_CHECK_RETURN
struct ddog_crasht_Result ddog_crasht_CrashInfo_set_metadata(struct ddog_crasht_CrashInfo *crashinfo,
                                                             struct ddog_crasht_Metadata metadata);

/**
 * Sets the crashinfo siginfo
 *
 * # Safety
 * `crashinfo` must be a valid pointer to a `CrashInfo` object.
 * All references inside `metadata` must be valid.
 * Strings are copied into the crashinfo, and do not need to outlive this call.
 */
DDOG_CHECK_RETURN
struct ddog_crasht_Result ddog_crasht_CrashInfo_set_siginfo(struct ddog_crasht_CrashInfo *crashinfo,
                                                            struct ddog_crasht_SigInfo siginfo);

/**
 * If `thread_id` is empty, sets `stacktrace` as the default stacktrace.
 * Otherwise, adds an additional stacktrace with id "thread_id".
 *
 * # Safety
 * `crashinfo` must be a valid pointer to a `CrashInfo` object.
 * All references inside `stacktraces` must be valid.
 * Strings are copied into the crashinfo, and do not need to outlive this call.
 */
DDOG_CHECK_RETURN
struct ddog_crasht_Result ddog_crasht_CrashInfo_set_stacktrace(struct ddog_crasht_CrashInfo *crashinfo,
                                                               ddog_CharSlice thread_id,
                                                               struct ddog_crasht_Slice_StackFrame stacktrace);

/**
 * Sets the timestamp to the given unix timestamp
 *
 * # Safety
 * `crashinfo` must be a valid pointer to a `CrashInfo` object.
 */
DDOG_CHECK_RETURN
struct ddog_crasht_Result ddog_crasht_CrashInfo_set_timestamp(struct ddog_crasht_CrashInfo *crashinfo,
                                                              struct ddog_Timespec ts);

/**
 * Sets the timestamp to the current time
 *
 * # Safety
 * `crashinfo` must be a valid pointer to a `CrashInfo` object.
 */
DDOG_CHECK_RETURN
struct ddog_crasht_Result ddog_crasht_CrashInfo_set_timestamp_to_now(struct ddog_crasht_CrashInfo *crashinfo);

/**
 * Exports `crashinfo` to the backend at `endpoint`
 * Note that we support the "file://" endpoint for local file output.
 * # Safety
 * `crashinfo` must be a valid pointer to a `CrashInfo` object.
 */
DDOG_CHECK_RETURN
struct ddog_crasht_Result ddog_crasht_CrashInfo_upload_to_endpoint(struct ddog_crasht_CrashInfo *crashinfo,
                                                                   const struct ddog_Endpoint *endpoint);

/**
 * Demangles the string "name".
 * If demangling fails, returns an empty string ""
 *
 * # Safety
 * `name` should be a valid reference to a utf8 encoded String.
 * The string is copied into the result, and does not need to outlive this call
 */
DDOG_CHECK_RETURN
struct ddog_crasht_StringWrapperResult ddog_crasht_demangle(ddog_CharSlice name,
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
DDOG_CHECK_RETURN struct ddog_crasht_Result ddog_crasht_receiver_entry_point_stdin(void);

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
struct ddog_crasht_Result ddog_crasht_receiver_entry_point_unix_socket(ddog_CharSlice socket_path);

#ifdef __cplusplus
} // extern "C"
#endif // __cplusplus

#endif /* DDOG_CRASHTRACKER_H */
