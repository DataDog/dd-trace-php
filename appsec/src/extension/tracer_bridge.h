// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "attributes.h"
#include <stdbool.h>
#include <stdint.h>
#include <zend.h>

#include <components-rs/common.h>
#include <ext/sidecar.h>
#include <ext/telemetry.h>
#include <tracer/priority_sampling/priority_sampling.h>
#include <tracer/user_request.h>

typedef zend_object root_span_t;

void dd_trace_startup(void);
// The request's root span changed (see _set_cur_span):
// dd_trace_emit_asm_event() emits once per root span.
void dd_trace_root_span_changed(void);

// This function should only be used in RINIT
bool dd_trace_enabled(void);

zend_object *nullable dd_trace_get_active_root_span(void);

// increases the refcount of tag, but not value (like zval_hash_add)
// however, it destroy value if the operation fails (unlike zval_hash_add)
bool dd_trace_span_add_attribute(
    zend_object *nonnull zobj, zend_string *nonnull tag, zval *nonnull value);

bool dd_trace_span_add_attribute_str(zend_object *nonnull zobj,
    const char *nonnull tag, size_t tag_len, const char *nonnull value,
    size_t value_len);

// Flush the tracer spans, can be used on RINIT
void dd_trace_close_all_spans_and_flush(void);

void dd_trace_emit_asm_event(void);

// Provides the array zval representing $root_span->attributes, if any.
// It is ready for modification, with refcount == 1
zval *nullable dd_trace_span_get_attributes(zend_object *nonnull);
// Provides $root_span->component (borrowed), if it is a string
zend_string *nullable dd_trace_span_get_component(zend_object *nonnull);
void dd_trace_span_add_propagated_tags(
    zend_string *nonnull key, zval *nonnull value);

zend_string *nullable dd_trace_get_formatted_runtime_id(bool persistent);

// Request body parsers; the returned MessagePack buffer must be released.
ddog_AppsecBodyMessagePack dd_trace_parse_json_body(const char *nonnull data,
    size_t len, int max_depth, ddog_AppsecBodyAllocator allocator);
ddog_AppsecBodyMessagePack dd_trace_parse_xml_body(const char *nonnull data,
    size_t len, int max_depth, ddog_AppsecBodyAllocator allocator);
void dd_trace_drop_body_msgpack(ddog_AppsecBodyMessagePack body);

bool dd_trace_user_req_add_listeners(
    ddtrace_user_req_listeners *nonnull listeners);

zend_string *nullable dd_ip_extraction_find(zval *nonnull server);

// The tracer's http.url (query string filtered/obfuscated). Empty if unknown.
zend_string *nonnull dd_trace_build_req_url(const zend_array *nonnull server);

struct telemetry_rc_info dd_trace_get_telemetry_rc_info(void);

// The merged ASM_FEATURES remote configs for the current remote config target.
ddog_AsmFeatures dd_trace_get_asm_features(void);

// Keeps to the current remote config target: dd_trace_get_kept_asm_features()
// returns its ASM_FEATURES, as they are when called, whichever target the
// tracer follows meanwhile.
void dd_trace_keep_asm_features_target(void);
// The ASM_FEATURES of the current target if none was kept.
ddog_AsmFeatures dd_trace_get_kept_asm_features(void);
