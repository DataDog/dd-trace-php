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
void dd_trace_shutdown(void);
void dd_trace_rinit(void);

// This function should only be used in RINIT
bool dd_trace_enabled(void);

zend_object *nullable dd_trace_get_active_root_span(void);

// increases the refcount of tag, but not value (like zval_hash_add)
// however, it destroy value if the operation fails (unlike zval_hash_add)
bool dd_trace_span_add_tag(
    zend_object *nonnull zobj, zend_string *nonnull tag, zval *nonnull value);

bool dd_trace_span_add_tag_str(zend_object *nonnull zobj,
    const char *nonnull tag, size_t tag_len, const char *nonnull value,
    size_t value_len);

// Flush the tracer spans, can be used on RINIT
void dd_trace_close_all_spans_and_flush(void);

void dd_trace_emit_asm_event(void);

// Provides the array zval representing $root_span->meta, if any.
// It is ready for modification, with refcount == 1
zval *nullable dd_trace_span_get_meta(zend_object *nonnull);
zval *nullable dd_trace_span_get_metrics(zend_object *nonnull);
zval *nullable dd_trace_span_get_meta_struct(zend_object *nonnull);
void dd_trace_span_add_propagated_tags(
    zend_string *nonnull key, zval *nonnull value);

zend_string *nullable dd_trace_get_formatted_runtime_id(bool persistent);

// The sidecar connection of the thread identifies the helper client; a
// client_init starts a new one.
ddog_AppsecCResponse dd_trace_send_appsec_message(
    const uint8_t *nonnull request, size_t request_len, bool client_init);
void dd_trace_free_appsec_message_response(ddog_AppsecCResponse response);

// Request body parsers; see ddog_appsec_parse_json/xml. The tape must be
// released with dd_trace_drop_body_tape.
ddog_AppsecBodyTape dd_trace_parse_json_body(
    const char *nonnull data, size_t len, int max_depth);
ddog_AppsecBodyTape dd_trace_parse_xml_body(
    const char *nonnull data, size_t len, int max_depth);
void dd_trace_drop_body_tape(ddog_AppsecBodyTape tape);

// Set sampling priority on root span
void dd_trace_set_priority_sampling_on_span_zobj(zend_object *nonnull root_span,
    zend_long priority, enum dd_sampling_mechanism mechanism);
zend_long dd_trace_get_priority_sampling_on_span_zobj(
    zend_object *nonnull root_span);

bool dd_trace_user_req_add_listeners(
    ddtrace_user_req_listeners *nonnull listeners);

zend_string *nullable dd_ip_extraction_find(zval *nonnull server);

struct telemetry_rc_info dd_trace_get_telemetry_rc_info(void);

zend_string *nullable dd_trace_guess_endpoint_from_url(
    const char *nonnull url, size_t url_len);

