// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.
#pragma once

#include "attributes.h"
#include <php.h>
#include <stddef.h>

/**
 * Parse possibly truncated JSON data. Whatever precedes a truncation or a
 * syntax error is kept.
 *
 * @param max_depth containers nested deeper are kept empty; negative means
 *        unlimited
 * @return the parsed value, or an undef zval if nothing could be parsed
 */
zval dd_parse_json_truncated(
    const char *nonnull json_data, size_t json_len, int max_depth);

/**
 * Parse possibly truncated XML data into
 * {<tag>: [{"@attr1": "...", ...}, "text...", {child elements...}]}.
 * External entities are never loaded.
 *
 * @param max_depth the content of elements nested deeper is dropped
 * @return the parsed value, or a null zval if nothing could be parsed
 */
zval dd_parse_xml_truncated(
    const char *nonnull xml_data, size_t xml_len, int max_depth);
