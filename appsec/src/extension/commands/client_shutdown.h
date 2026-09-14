// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include <stdbool.h>

#include "../attributes.h"
#include "../network.h"

struct client_shutdown_data {
    bool clean;
    const char *nullable error;
};

dd_result dd_client_shutdown(
    dd_conn *nonnull conn, const struct client_shutdown_data *nonnull data);
