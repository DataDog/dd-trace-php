// Copyright 2021-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0


#ifndef DDOG_PROFILING_H
#define DDOG_PROFILING_H

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
 *     This function assumes that the crash-tracker has previously been
 *     initialized.
 * # Safety
 *     Crash-tracking functions are not reentrant.
 *     No other crash-handler functions should be called concurrently.
 * # Atomicity
 *     This function is not atomic. A crash during its execution may lead to
 *     unexpected crash-handling behaviour.
 */
DDOG_CHECK_RETURN struct ddog_prof_CrashtrackerResult ddog_prof_Crashtracker_shutdown(void);

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
 *     This function assumes that the crash-tracker has previously been
 *     initialized.
 * # Safety
 *     Crash-tracking functions are not reentrant.
 *     No other crash-handler functions should be called concurrently.
 * # Atomicity
 *     This function is not atomic. A crash during its execution may lead to
 *     unexpected crash-handling behaviour.
 */
DDOG_CHECK_RETURN
struct ddog_prof_CrashtrackerResult ddog_prof_Crashtracker_update_on_fork(struct ddog_prof_CrashtrackerConfiguration config,
                                                                          struct ddog_prof_CrashtrackerReceiverConfig receiver_config,
                                                                          struct ddog_prof_CrashtrackerMetadata metadata);

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
struct ddog_prof_CrashtrackerResult ddog_prof_Crashtracker_receiver_entry_point_stdin(void);

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
struct ddog_prof_CrashtrackerResult ddog_prof_Crashtracker_receiver_entry_point_unix_socket(ddog_CharSlice socket_path);

/**
 * Initialize the crash-tracking infrastructure, spawning a receiver process in case of crash
 * and writing to its STDIN.
 *
 * # Preconditions
 *     None.
 * # Safety
 *     Crash-tracking functions are not reentrant.
 *     No other crash-handler functions should be called concurrently.
 * # Atomicity
 *     This function is not atomic. A crash during its execution may lead to
 *     unexpected crash-handling behaviour.
 */
DDOG_CHECK_RETURN
struct ddog_prof_CrashtrackerResult ddog_prof_Crashtracker_init_with_receiver(struct ddog_prof_CrashtrackerConfiguration config,
                                                                              struct ddog_prof_CrashtrackerReceiverConfig receiver_config,
                                                                              struct ddog_prof_CrashtrackerMetadata metadata);

/**
 * Initialize the crash-tracking infrastructure, writing to an unix socket in case of crash.
 *
 * # Preconditions
 *     None.
 * # Safety
 *     Crash-tracking functions are not reentrant.
 *     No other crash-handler functions should be called concurrently.
 * # Atomicity
 *     This function is not atomic. A crash during its execution may lead to
 *     unexpected crash-handling behaviour.
 */
DDOG_CHECK_RETURN
struct ddog_prof_CrashtrackerResult ddog_prof_Crashtracker_init_with_unix_socket(struct ddog_prof_CrashtrackerConfiguration config,
                                                                                 ddog_CharSlice socket_path,
                                                                                 struct ddog_prof_CrashtrackerMetadata metadata);

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
DDOG_CHECK_RETURN struct ddog_prof_CrashtrackerResult ddog_prof_Crashtracker_reset_counters(void);

/**
 * Atomically increments the count associated with `op`.
 * Useful for tracking what operations were occuring when a crash occurred.
 *
 * # Safety
 * No safety concerns.
 */
DDOG_CHECK_RETURN
struct ddog_prof_CrashtrackerResult ddog_prof_Crashtracker_begin_profiling_op(enum ddog_prof_ProfilingOpTypes op);

/**
 * Atomically decrements the count associated with `op`.
 * Useful for tracking what operations were occuring when a crash occurred.
 *
 * # Safety
 * No safety concerns.
 */
DDOG_CHECK_RETURN
struct ddog_prof_CrashtrackerResult ddog_prof_Crashtracker_end_profiling_op(enum ddog_prof_ProfilingOpTypes op);

/**
 * Create a new crashinfo, and returns an opaque reference to it.
 * # Safety
 * No safety issues.
 */
DDOG_CHECK_RETURN struct ddog_prof_CrashInfoNewResult ddog_crashinfo_new(void);

/**
 * # Safety
 * The `crash_info` can be null, but if non-null it must point to a CrashInfo
 * made by this module, which has not previously been dropped.
 */
void ddog_crashinfo_drop(struct ddog_prof_CrashInfo *crashinfo);

/**
 * Best effort attempt to normalize all `ip` on the stacktrace.
 * `pid` must be the pid of the currently active process where the ips came from.
 *
 * # Safety
 * `crashinfo` must be a valid pointer to a `CrashInfo` object.
 */
DDOG_CHECK_RETURN
struct ddog_prof_CrashtrackerResult ddog_crashinfo_normalize_ips(struct ddog_prof_CrashInfo *crashinfo,
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
struct ddog_prof_CrashtrackerResult ddog_crashinfo_add_counter(struct ddog_prof_CrashInfo *crashinfo,
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
struct ddog_prof_CrashtrackerResult ddog_crashinfo_add_file(struct ddog_prof_CrashInfo *crashinfo,
                                                            ddog_CharSlice name);

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
struct ddog_prof_CrashtrackerResult ddog_crashinfo_add_tag(struct ddog_prof_CrashInfo *crashinfo,
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
struct ddog_prof_CrashtrackerResult ddog_crashinfo_set_metadata(struct ddog_prof_CrashInfo *crashinfo,
                                                                struct ddog_prof_CrashtrackerMetadata metadata);

/**
 * Sets the crashinfo siginfo
 *
 * # Safety
 * `crashinfo` must be a valid pointer to a `CrashInfo` object.
 * All references inside `metadata` must be valid.
 * Strings are copied into the crashinfo, and do not need to outlive this call.
 */
DDOG_CHECK_RETURN
struct ddog_prof_CrashtrackerResult ddog_crashinfo_set_siginfo(struct ddog_prof_CrashInfo *crashinfo,
                                                               struct ddog_prof_SigInfo siginfo);

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
struct ddog_prof_CrashtrackerResult ddog_crashinfo_set_stacktrace(struct ddog_prof_CrashInfo *crashinfo,
                                                                  ddog_CharSlice thread_id,
                                                                  struct ddog_prof_Slice_StackFrame stacktrace);

/**
 * Sets the timestamp to the given unix timestamp
 *
 * # Safety
 * `crashinfo` must be a valid pointer to a `CrashInfo` object.
 */
DDOG_CHECK_RETURN
struct ddog_prof_CrashtrackerResult ddog_crashinfo_set_timestamp(struct ddog_prof_CrashInfo *crashinfo,
                                                                 int64_t secs,
                                                                 uint32_t nsecs);

/**
 * Sets the timestamp to the current time
 *
 * # Safety
 * `crashinfo` must be a valid pointer to a `CrashInfo` object.
 */
DDOG_CHECK_RETURN
struct ddog_prof_CrashtrackerResult ddog_crashinfo_set_timestamp_to_now(struct ddog_prof_CrashInfo *crashinfo);

/**
 * Exports `crashinfo` to the backend at `endpoint`
 * Note that we support the "file://" endpoint for local file output.
 * # Safety
 * `crashinfo` must be a valid pointer to a `CrashInfo` object.
 */
DDOG_CHECK_RETURN
struct ddog_prof_CrashtrackerResult ddog_crashinfo_upload_to_endpoint(struct ddog_prof_CrashInfo *crashinfo,
                                                                      struct ddog_prof_CrashtrackerConfiguration config);

/**
 * Demangles the string "name".
 * If demangling fails, returns an empty string ""
 *
 * # Safety
 * `name` should be a valid reference to a utf8 encoded String.
 * The string is copied into the result, and does not need to outlive this call
 */
DDOG_CHECK_RETURN
struct ddog_prof_StringWrapperResult ddog_demangle(ddog_CharSlice name,
                                                   enum ddog_prof_DemangleOptions options);

DDOG_CHECK_RETURN struct ddog_prof_Exporter_Slice_File ddog_prof_Exporter_Slice_File_empty(void);

/**
 * Creates an endpoint that uses the agent.
 * # Arguments
 * * `base_url` - Contains a URL with scheme, host, and port e.g. "https://agent:8126/".
 */
struct ddog_prof_Endpoint ddog_prof_Endpoint_agent(ddog_CharSlice base_url);

/**
 * Creates an endpoint that uses the Datadog intake directly aka agentless.
 * # Arguments
 * * `site` - Contains a host and port e.g. "datadoghq.com".
 * * `api_key` - Contains the Datadog API key.
 */
struct ddog_prof_Endpoint ddog_prof_Endpoint_agentless(ddog_CharSlice site, ddog_CharSlice api_key);

/**
 * Creates an endpoint that writes to a file.
 * Useful for local debugging.
 * Currently only supported by the crashtracker.
 * # Arguments
 * * `filename` - Path to the output file "/tmp/file.txt".
 */
struct ddog_prof_Endpoint ddog_Endpoint_file(ddog_CharSlice filename);

/**
 * Creates a new exporter to be used to report profiling data.
 * # Arguments
 * * `profiling_library_name` - Profiling library name, usually dd-trace-something, e.g.
 *   "dd-trace-rb". See
 *   https://datadoghq.atlassian.net/wiki/spaces/PROF/pages/1538884229/Client#Header-values
 *   (Datadog internal link)
 *   for a list of common values.
 * * `profliling_library_version` - Version used when publishing the profiling library to a package
 *   manager
 * * `family` - Profile family, e.g. "ruby"
 * * `tags` - Tags to include with every profile reported by this exporter. It's also possible to
 *   include profile-specific tags, see `additional_tags` on `profile_exporter_build`.
 * * `endpoint` - Configuration for reporting data
 * # Safety
 * All pointers must refer to valid objects of the correct types.
 */
DDOG_CHECK_RETURN
struct ddog_prof_Exporter_NewResult ddog_prof_Exporter_new(ddog_CharSlice profiling_library_name,
                                                           ddog_CharSlice profiling_library_version,
                                                           ddog_CharSlice family,
                                                           const struct ddog_Vec_Tag *tags,
                                                           struct ddog_prof_Endpoint endpoint);

/**
 * # Safety
 * The `exporter` may be null, but if non-null the pointer must point to a
 * valid `ddog_prof_Exporter_Request` object made by the Rust Global
 * allocator that has not already been dropped.
 */
void ddog_prof_Exporter_drop(struct ddog_prof_Exporter *exporter);

/**
 * If successful, builds a `ddog_prof_Exporter_Request` object based on the
 * profile data supplied. If unsuccessful, it returns an error message.
 *
 * For details on the `optional_internal_metadata_json`, please reference the Datadog-internal
 * "RFC: Attaching internal metadata to pprof profiles".
 * If you use this parameter, please update the RFC with your use-case, so we can keep track of how
 * this is getting used.
 *
 * For details on the `optional_info_json`, please reference the Datadog-internal
 * "RFC: Pprof System Info Support".
 *
 * # Safety
 * The `exporter`, `optional_additional_stats`, and `optional_endpoint_stats` args should be
 * valid objects created by this module.
 * NULL is allowed for `optional_additional_tags`, `optional_endpoints_stats`,
 * `optional_internal_metadata_json` and `optional_info_json`.
 */
DDOG_CHECK_RETURN
struct ddog_prof_Exporter_Request_BuildResult ddog_prof_Exporter_Request_build(struct ddog_prof_Exporter *exporter,
                                                                               struct ddog_Timespec start,
                                                                               struct ddog_Timespec end,
                                                                               struct ddog_prof_Exporter_Slice_File files_to_compress_and_export,
                                                                               struct ddog_prof_Exporter_Slice_File files_to_export_unmodified,
                                                                               const struct ddog_Vec_Tag *optional_additional_tags,
                                                                               const struct ddog_prof_ProfiledEndpointsStats *optional_endpoints_stats,
                                                                               const ddog_CharSlice *optional_internal_metadata_json,
                                                                               const ddog_CharSlice *optional_info_json,
                                                                               uint64_t timeout_ms);

/**
 * # Safety
 * Each pointer of `request` may be null, but if non-null the inner-most
 * pointer must point to a valid `ddog_prof_Exporter_Request` object made by
 * the Rust Global allocator.
 */
void ddog_prof_Exporter_Request_drop(struct ddog_prof_Exporter_Request **request);

/**
 * Sends the request, returning the HttpStatus.
 *
 * # Arguments
 * * `exporter` - Borrows the exporter for sending the request.
 * * `request` - Takes ownership of the request, replacing it with a null pointer. This is why it
 *   takes a double-pointer, rather than a single one.
 * * `cancel` - Borrows the cancel, if any.
 *
 * # Safety
 * All non-null arguments MUST have been created by created by apis in this module.
 */
DDOG_CHECK_RETURN
struct ddog_prof_Exporter_SendResult ddog_prof_Exporter_send(struct ddog_prof_Exporter *exporter,
                                                             struct ddog_prof_Exporter_Request **request,
                                                             const struct ddog_CancellationToken *cancel);

/**
 * Can be passed as an argument to send and then be used to asynchronously cancel it from a
 * different thread.
 */
DDOG_CHECK_RETURN struct ddog_CancellationToken *ddog_CancellationToken_new(void);

/**
 * A cloned CancellationToken is connected to the CancellationToken it was created from.
 * Either the cloned or the original token can be used to cancel or provided as arguments to send.
 * The useful part is that they have independent lifetimes and can be dropped separately.
 *
 * Thus, it's possible to do something like:
 * ```c
 * cancel_t1 = ddog_CancellationToken_new();
 * cancel_t2 = ddog_CancellationToken_clone(cancel_t1);
 *
 * // On thread t1:
 *     ddog_prof_Exporter_send(..., cancel_t1);
 *     ddog_CancellationToken_drop(cancel_t1);
 *
 * // On thread t2:
 *     ddog_CancellationToken_cancel(cancel_t2);
 *     ddog_CancellationToken_drop(cancel_t2);
 * ```
 *
 * Without clone, both t1 and t2 would need to synchronize to make sure neither was using the
 * cancel before it could be dropped. With clone, there is no need for such synchronization, both
 * threads have their own cancel and should drop that cancel after they are done with it.
 *
 * # Safety
 * If the `token` is non-null, it must point to a valid object.
 */
DDOG_CHECK_RETURN
struct ddog_CancellationToken *ddog_CancellationToken_clone(const struct ddog_CancellationToken *token);

/**
 * Cancel send that is being called in another thread with the given token.
 * Note that cancellation is a terminal state; cancelling a token more than once does nothing.
 * Returns `true` if token was successfully cancelled.
 */
bool ddog_CancellationToken_cancel(const struct ddog_CancellationToken *cancel);

/**
 * # Safety
 * The `token` can be null, but non-null values must be created by the Rust
 * Global allocator and must have not been dropped already.
 */
void ddog_CancellationToken_drop(struct ddog_CancellationToken *token);

/**
 * Create a new profile with the given sample types. Must call
 * `ddog_prof_Profile_drop` when you are done with the profile.
 *
 * # Arguments
 * * `sample_types`
 * * `period` - Optional period of the profile. Passing None/null translates to zero values.
 * * `start_time` - Optional time the profile started at. Passing None/null will use the current
 *   time.
 *
 * # Safety
 * All slices must be have pointers that are suitably aligned for their type
 * and must have the correct number of elements for the slice.
 */
DDOG_CHECK_RETURN
struct ddog_prof_Profile_NewResult ddog_prof_Profile_new(struct ddog_prof_Slice_ValueType sample_types,
                                                         const struct ddog_prof_Period *period,
                                                         const struct ddog_Timespec *start_time);

/**
 * # Safety
 * The `profile` can be null, but if non-null it must point to a Profile
 * made by this module, which has not previously been dropped.
 */
void ddog_prof_Profile_drop(struct ddog_prof_Profile *profile);

/**
 * # Safety
 * The `profile` ptr must point to a valid Profile object created by this
 * module. All pointers inside the `sample` need to be valid for the duration
 * of this call.
 *
 * If successful, it returns the Ok variant.
 * On error, it holds an error message in the error variant.
 *
 * # Safety
 * The `profile` ptr must point to a valid Profile object created by this
 * module.
 * This call is _NOT_ thread-safe.
 */
DDOG_CHECK_RETURN
struct ddog_prof_Profile_Result ddog_prof_Profile_add(struct ddog_prof_Profile *profile,
                                                      struct ddog_prof_Sample sample,
                                                      int64_t timestamp);

/**
 * Associate an endpoint to a given local root span id.
 * During the serialization of the profile, an endpoint label will be added
 * to all samples that contain a matching local root span id label.
 *
 * Note: calling this API causes the "trace endpoint" and "local root span id" strings
 * to be interned, even if no matching sample is found.
 *
 * # Arguments
 * * `profile` - a reference to the profile that will contain the samples.
 * * `local_root_span_id`
 * * `endpoint` - the value of the endpoint label to add for matching samples.
 *
 * # Safety
 * The `profile` ptr must point to a valid Profile object created by this
 * module.
 * This call is _NOT_ thread-safe.
 */
DDOG_CHECK_RETURN
struct ddog_prof_Profile_Result ddog_prof_Profile_set_endpoint(struct ddog_prof_Profile *profile,
                                                               uint64_t local_root_span_id,
                                                               ddog_CharSlice endpoint);

/**
 * Count the number of times an endpoint has been seen.
 *
 * # Arguments
 * * `profile` - a reference to the profile that will contain the samples.
 * * `endpoint` - the endpoint label for which the count will be incremented
 *
 * # Safety
 * The `profile` ptr must point to a valid Profile object created by this
 * module.
 * This call is _NOT_ thread-safe.
 */
DDOG_CHECK_RETURN
struct ddog_prof_Profile_Result ddog_prof_Profile_add_endpoint_count(struct ddog_prof_Profile *profile,
                                                                     ddog_CharSlice endpoint,
                                                                     int64_t value);

/**
 * Add a poisson-based upscaling rule which will be use to adjust values and make them
 * closer to reality.
 *
 * # Arguments
 * * `profile` - a reference to the profile that will contain the samples.
 * * `offset_values` - offset of the values
 * * `label_name` - name of the label used to identify sample(s)
 * * `label_value` - value of the label used to identify sample(s)
 * * `sum_value_offset` - offset of the value used as a sum (compute the average with
 *   `count_value_offset`)
 * * `count_value_offset` - offset of the value used as a count (compute the average with
 *   `sum_value_offset`)
 * * `sampling_distance` - this is the threshold for this sampling window. This value must not be
 *   equal to 0
 *
 * # Safety
 * This function must be called before serialize and must not be called after.
 * The `profile` ptr must point to a valid Profile object created by this
 * module.
 * This call is _NOT_ thread-safe.
 */
DDOG_CHECK_RETURN
struct ddog_prof_Profile_Result ddog_prof_Profile_add_upscaling_rule_poisson(struct ddog_prof_Profile *profile,
                                                                             struct ddog_prof_Slice_Usize offset_values,
                                                                             ddog_CharSlice label_name,
                                                                             ddog_CharSlice label_value,
                                                                             uintptr_t sum_value_offset,
                                                                             uintptr_t count_value_offset,
                                                                             uint64_t sampling_distance);

/**
 * Add a proportional-based upscaling rule which will be use to adjust values and make them
 * closer to reality.
 *
 * # Arguments
 * * `profile` - a reference to the profile that will contain the samples.
 * * `offset_values` - offset of the values
 * * `label_name` - name of the label used to identify sample(s)
 * * `label_value` - value of the label used to identify sample(s)
 * * `total_sampled` - number of sampled event (found in the pprof). This value must not be equal
 *   to 0
 * * `total_real` - number of events the profiler actually witnessed. This value must not be equal
 *   to 0
 *
 * # Safety
 * This function must be called before serialize and must not be called after.
 * The `profile` ptr must point to a valid Profile object created by this
 * module.
 * This call is _NOT_ thread-safe.
 */
DDOG_CHECK_RETURN
struct ddog_prof_Profile_Result ddog_prof_Profile_add_upscaling_rule_proportional(struct ddog_prof_Profile *profile,
                                                                                  struct ddog_prof_Slice_Usize offset_values,
                                                                                  ddog_CharSlice label_name,
                                                                                  ddog_CharSlice label_value,
                                                                                  uint64_t total_sampled,
                                                                                  uint64_t total_real);

/**
 * # Safety
 * Only pass a reference to a valid `ddog_prof_EncodedProfile`, or null. A
 * valid reference also means that it hasn't already been dropped (do not
 * call this twice on the same object).
 */
void ddog_prof_EncodedProfile_drop(struct ddog_prof_EncodedProfile *profile);

/**
 * Serialize the aggregated profile.
 * Drains the data, and then resets the profile for future use.
 *
 * Don't forget to clean up the ok with `ddog_prof_EncodedProfile_drop` or
 * the error variant with `ddog_Error_drop` when you are done with them.
 *
 * # Arguments
 * * `profile` - a reference to the profile being serialized.
 * * `end_time` - optional end time of the profile. If None/null is passed, the current time will
 *   be used.
 * * `duration_nanos` - Optional duration of the profile. Passing None or a negative duration will
 *   mean the duration will based on the end time minus the start time, but under anomalous
 *   conditions this may fail as system clocks can be adjusted, or the programmer accidentally
 *   passed an earlier time. The duration of the serialized profile will be set to zero for these
 *   cases.
 * * `start_time` - Optional start time for the next profile.
 *
 * # Safety
 * The `profile` must point to a valid profile object.
 * The `end_time` must be null or otherwise point to a valid TimeSpec object.
 * The `duration_nanos` must be null or otherwise point to a valid i64.
 */
DDOG_CHECK_RETURN
struct ddog_prof_Profile_SerializeResult ddog_prof_Profile_serialize(struct ddog_prof_Profile *profile,
                                                                     const struct ddog_Timespec *end_time,
                                                                     const int64_t *duration_nanos,
                                                                     const struct ddog_Timespec *start_time);

DDOG_CHECK_RETURN struct ddog_Slice_U8 ddog_Vec_U8_as_slice(const struct ddog_Vec_U8 *vec);

/**
 * Resets all data in `profile` except the sample types and period. Returns
 * true if it successfully reset the profile and false otherwise. The profile
 * remains valid if false is returned.
 *
 * # Arguments
 * * `profile` - A mutable reference to the profile to be reset.
 * * `start_time` - The time of the profile (after reset). Pass None/null to use the current time.
 *
 * # Safety
 * The `profile` must meet all the requirements of a mutable reference to the profile. Given this
 * can be called across an FFI boundary, the compiler cannot enforce this.
 * If `time` is not null, it must point to a valid Timespec object.
 */
DDOG_CHECK_RETURN
struct ddog_prof_Profile_Result ddog_prof_Profile_reset(struct ddog_prof_Profile *profile,
                                                        const struct ddog_Timespec *start_time);

#ifdef __cplusplus
} // extern "C"
#endif // __cplusplus

#endif /* DDOG_PROFILING_H */
