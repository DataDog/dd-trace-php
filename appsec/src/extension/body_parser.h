// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.
#pragma once

#include "attributes.h"
#include "ddtrace.h"
#include "msgpack_helpers.h"

void dd_body_parser_startup(void);

/**
 * Parse possibly truncated JSON data. Whatever precedes a truncation or a
 * syntax error is kept.
 *
 * @param max_depth containers nested deeper are kept empty; negative means
 *        unlimited
 * @return Rust-owned MessagePack bytes, or an empty buffer on failure
 */
ddog_AppsecBodyMessagePack dd_parse_json_truncated(
    const char *nonnull json_data, size_t json_len, int max_depth);

/**
 * Parse possibly truncated XML data into
 * {<tag>: [{"@attr1": "...", ...}, "text...", {child elements...}]}.
 * External entities are never loaded.
 *
 * @param max_depth the content of elements nested deeper is dropped
 * @return Rust-owned MessagePack bytes, or an empty buffer on failure
 */
ddog_AppsecBodyMessagePack dd_parse_xml_truncated(
    const char *nonnull xml_data, size_t xml_len, int max_depth);

static inline bool dd_body_msgpack_has_value(ddog_AppsecBodyMessagePack body)
{
    return (body.status == DDOG_APPSEC_BODY_STATUS_COMPLETE ||
               body.status == DDOG_APPSEC_BODY_STATUS_PARTIAL) &&
           !(body.len == 1 && body.ptr[0] == 0xc0);
}

#ifdef TESTING
/** Decode finished MessagePack for PHP tests, then release the Rust buffer.
 */
void dd_body_msgpack_to_zval(
    ddog_AppsecBodyMessagePack body, zval *nonnull result);
#endif

/** Write one MessagePack object. The caller retains ownership of its buffer.
 */
bool dd_body_msgpack_write(mpack_writer_t *nonnull writer,
    const ddog_AppsecBodyMessagePack *nonnull body);
