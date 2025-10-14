// Copyright 2021-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0


#ifndef DDOG_LIBRARY_CONFIG_H
#define DDOG_LIBRARY_CONFIG_H

#pragma once

#include "common.h"

#ifdef __cplusplus
extern "C" {
#endif // __cplusplus

struct ddog_Configurator *ddog_library_configurator_new(bool debug_logs, ddog_CharSlice language);

void ddog_library_configurator_with_local_path(struct ddog_Configurator *c,
                                               struct ddog_CStr local_path);

void ddog_library_configurator_with_fleet_path(struct ddog_Configurator *c,
                                               struct ddog_CStr local_path);

void ddog_library_configurator_with_process_info(struct ddog_Configurator *c,
                                                 struct ddog_ProcessInfo p);

void ddog_library_configurator_with_detect_process_info(struct ddog_Configurator *c);

void ddog_library_configurator_drop(struct ddog_Configurator*);

struct ddog_LibraryConfigLoggedResult ddog_library_configurator_get(const struct ddog_Configurator *configurator);

/**
 * Returns a static null-terminated string, containing the name of the environment variable
 * associated with the library configuration
 */
struct ddog_CStr ddog_library_config_source_to_string(enum ddog_LibraryConfigSource name);

/**
 * Returns a static null-terminated string with the path to the managed stable config yaml config
 * file
 */
struct ddog_CStr ddog_library_config_fleet_stable_config_path(void);

/**
 * Returns a static null-terminated string with the path to the local stable config yaml config
 * file
 */
struct ddog_CStr ddog_library_config_local_stable_config_path(void);

void ddog_library_config_drop(struct ddog_LibraryConfigLoggedResult config_result);

/**
 * Allocates and returns a pointer to a new `TracerMetadata` object on the heap.
 *
 * # Safety
 * This function returns a raw pointer. The caller is responsible for calling
 * `ddog_tracer_metadata_free` to deallocate the memory.
 *
 * # Returns
 * A non-null pointer to a newly allocated `TracerMetadata` instance.
 */
struct ddog_TracerMetadata *ddog_tracer_metadata_new(void);

/**
 * Frees a `TracerMetadata` instance previously allocated with `ddog_tracer_metadata_new`.
 *
 * # Safety
 * - `ptr` must be a pointer previously returned by `ddog_tracer_metadata_new`.
 * - Double-freeing or passing an invalid pointer results in undefined behavior.
 * - Passing a null pointer is safe and does nothing.
 */
void ddog_tracer_metadata_free(struct ddog_TracerMetadata *ptr);

/**
 * Sets a field of the `TracerMetadata` object pointed to by `ptr`.
 *
 * # Arguments
 * - `ptr`: Pointer to a `TracerMetadata` instance.
 * - `kind`: The metadata field to set (as defined in `MetadataKind`).
 * - `value`: A null-terminated C string representing the value to set.
 *
 * # Safety
 * - Both `ptr` and `value` must be non-null.
 * - `value` must point to a valid UTF-8 null-terminated string.
 * - If the string is not valid UTF-8, the function does nothing.
 */
void ddog_tracer_metadata_set(struct ddog_TracerMetadata *ptr,
                              enum ddog_MetadataKind kind,
                              const char *value);

/**
 * Serializes the `TracerMetadata` into a platform-specific memory handle (e.g., memfd on Linux).
 *
 * # Safety
 * - `ptr` must be a valid, non-null pointer to a `TracerMetadata`.
 *
 * # Returns
 * - On Linux: a `TracerMemfdHandle` containing a raw file descriptor to a memory file.
 * - On unsupported platforms: an error.
 * - On failure: propagates any internal errors from the metadata storage process.
 *
 * # Platform Support
 * This function currently only supports Linux via `memfd`. On other platforms,
 * it will return an error.
 */
struct ddog_Result_TracerMemfdHandle ddog_tracer_metadata_store(struct ddog_TracerMetadata *ptr);

#ifdef __cplusplus
}  // extern "C"
#endif  // __cplusplus

#endif  /* DDOG_LIBRARY_CONFIG_H */
