// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "ddtrace.h"
#include <stddef.h>

void dd_telemetry_add_metric(zend_string *nonnull name_zstr, double value,
    zend_string *nonnull tags_zstr, ddtrace_metric_type type);

void dd_telemetry_add_sdk_event(char *nonnull event_type, size_t event_type_len);
void dd_telemetry_startup(void);

void dd_telemetry_helper_conn_error(void);
void dd_telemetry_helper_conn_success(void);
void dd_telemetry_helper_conn_close(void);
