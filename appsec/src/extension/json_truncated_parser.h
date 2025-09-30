// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#ifdef __cplusplus
extern "C" {
#endif

#include <php.h>
#include <stddef.h>

/**
 * Parse possibly truncated JSON data using RapidJSON with permissive flags.
 * Based on nginx-datadog implementation for handling incomplete JSON.
 *
 * @param json_data Pointer to JSON string data
 * @param json_len Length of JSON data
 * @param max_depth Maximum recursion depth for nested structures
 * @return zval containing parsed data, or NULL zval on failure
 */
zval dd_parse_json_truncated(const char* json_data, size_t json_len, int max_depth);

#ifdef __cplusplus
}
#endif
