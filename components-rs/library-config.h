// Copyright 2021-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0


#ifndef DDOG_LIBRARY_CONFIG_H
#define DDOG_LIBRARY_CONFIG_H

#pragma once

#include "common.h"

#ifdef __cplusplus
extern "C" {
#endif // __cplusplus

struct ddog_Configurator *ddog_library_configurator_new(bool debug_logs);

void ddog_library_configurator_drop(struct ddog_Configurator*);

struct ddog_Result_VecLibraryConfig ddog_library_configurator_get_path(const struct ddog_Configurator *configurator,
                                                                       struct ddog_ProcessInfo process_info,
                                                                       ddog_CharSlice path);

struct ddog_Result_VecLibraryConfig ddog_library_configurator_get(const struct ddog_Configurator *configurator,
                                                                  struct ddog_ProcessInfo process_info);

struct ddog_Result_VecLibraryConfig ddog_library_configurator_get_from_bytes(const struct ddog_Configurator *configurator,
                                                                             struct ddog_ProcessInfo process_info,
                                                                             ddog_ByteSlice config_bytes);

struct ddog_CStr ddog_library_config_name_to_env(enum ddog_LibraryConfigName name);

void ddog_library_config_drop(struct ddog_Vec_LibraryConfig);

#ifdef __cplusplus
} // extern "C"
#endif // __cplusplus

#endif /* DDOG_LIBRARY_CONFIG_H */
