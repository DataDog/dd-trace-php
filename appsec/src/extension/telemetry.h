// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "ddtrace.h"
#include <stddef.h>

void dd_telemetry_add_metric(const char *nonnull name, size_t name_len,
    double value, const char *nonnull tags_str, size_t tags_len, ddtrace_metric_type type);

void dd_telemetry_add_sdk_event(char *nonnull event_type, size_t event_type_len);
