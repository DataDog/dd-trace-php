// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2025 Datadog, Inc.
#pragma once

#include <php.h>
#include <stdbool.h>
#include <stddef.h>
#include "attributes.h"

bool dd_xml_parser_startup(void);
void dd_xml_parser_shutdown(void);

/**
 * Parse possibly truncated XML data using libxml2 SAX parser.
 * Produces the same output format as the existing _convert_xml_impl:
 * {<tag>: [{@attr1: "...", ...}, "text...", {child elements...}]}
 * External entities and other dangerous features are disabled.
 *
 * @param xml_data Pointer to XML string data
 * @param xml_len Length of XML data
 * @param max_depth Maximum recursion depth for nested elements
 * @return zval containing parsed data, or NULL zval on failure
 */
zval dd_parse_xml_truncated(
    const char *nonnull xml_data, size_t xml_len, int max_depth);
