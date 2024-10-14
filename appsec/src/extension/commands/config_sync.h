// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "../network.h"

struct config_sync_data {
    char *nullable rem_cfg_path;
};

dd_result dd_config_sync(
    dd_conn *nonnull conn, const struct config_sync_data *nonnull data);
