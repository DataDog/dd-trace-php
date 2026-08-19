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

void dd_telemetry_add_sdk_event(
    char *nonnull event_type, size_t event_type_len);
void dd_telemetry_add_missing_user_login(const char *nonnull event_type,
    size_t event_type_len, const char *nonnull framework, size_t framework_len);
void dd_telemetry_add_missing_user_id(const char *nonnull event_type,
    size_t event_type_len, const char *nonnull framework, size_t framework_len);
void dd_telemetry_startup(void);
void dd_telemetry_mshutdown(void);

void dd_telemetry_rinit(void);
void dd_telemetry_note_helper_string_meta(const char *nonnull key,
    size_t key_len, const char *nonnull val, size_t val_len);

// NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
void dd_telemetry_submit_duration_ext(double waf_ext_us, double rasp_ext_us);
void dd_telemetry_add_rasp_rule_skipped(
    zend_string *nonnull rule_type, zend_string *nullable rule_variant);

// The outcome of the API security decision taken for a request, as far as
// RFC-1012's api_security metrics are concerned.
typedef enum {
    // the request is not a candidate for schema extraction for a reason that
    // is not worth reporting (API security disabled, request blocked, trace
    // dropped, ...). No metric is emitted.
    DD_API_SEC_SKIP = 0,
    // the request would have been a candidate, but no HTTP route (or a
    // stand-in for it) could be determined: appsec.api_security.missing_route
    DD_API_SEC_MISSING_ROUTE,
    // the request was submitted for schema extraction: either
    // appsec.api_security.request.schema or .no_schema, depending on whether
    // the helper came back with a schema
    DD_API_SEC_EVALUATED,
} dd_api_sec_outcome;

// Called when the helper reports schemas (_dd.appsec.s.*) for the current
// request
void dd_telemetry_note_schema_extracted(void);
void dd_telemetry_add_api_security_request(
    zend_object *nullable root_span, dd_api_sec_outcome outcome);

void dd_telemetry_helper_conn_error(void);
void dd_telemetry_helper_conn_success(void);
void dd_telemetry_helper_conn_close(void);
