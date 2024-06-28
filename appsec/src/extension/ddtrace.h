// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "attributes.h"
#include <stdbool.h>
#include <zend.h>

static const int PRIORITY_SAMPLING_AUTO_KEEP = 1;
static const int PRIORITY_SAMPLING_AUTO_REJECT = 0;
static const int PRIORITY_SAMPLING_USER_KEEP = 2;
static const int PRIORITY_SAMPLING_USER_REJECT = -1;

enum dd_sampling_mechanism {
    DD_MECHANISM_DEFAULT = 0,
    DD_MECHANISM_AGENT_RATE = 1,
    DD_MECHANISM_REMOTE_RATE = 2,
    DD_MECHANISM_RULE = 3,
    DD_MECHANISM_MANUAL = 4,
};

typedef zend_object root_span_t;

void dd_trace_startup(void);
void dd_trace_shutdown(void);

// Returns the tracer version
const char *nullable dd_trace_version(void);
// This function should only be used in RINIT
bool dd_trace_enabled(void);
// This function should be used before loading tracer symbols
bool dd_trace_loaded(void);

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

// Provides the array zval representing $root_span->meta, if any.
// It is ready for modification, with refcount == 1
zval *nullable dd_trace_span_get_meta(zend_object *nonnull);
zval *nullable dd_trace_span_get_metrics(zend_object *nonnull);
zend_string *nullable dd_trace_get_formatted_runtime_id(bool persistent);

// Set sampling priority on root span
void dd_trace_set_priority_sampling_on_span_zobj(zend_object *nonnull root_span,
    zend_long priority, enum dd_sampling_mechanism mechanism);

typedef struct _ddtrace_user_req_listeners ddtrace_user_req_listeners;
struct _ddtrace_user_req_listeners {
    int priority;
    zend_array *nullable (*nonnull start_user_req)(
        ddtrace_user_req_listeners *nonnull self, zend_object *nonnull span,
        zend_array *nonnull variables, zval *nullable rbe_zv);
    zend_array *nullable(*nonnull response_committed)(
        ddtrace_user_req_listeners *nonnull self, zend_object *nonnull span,
        int status, zend_array *nonnull headers, zval *nullable entity);
    void (*nonnull finish_user_req)(
        ddtrace_user_req_listeners *nonnull self, zend_object *nonnull span);
    void (*nonnull set_blocking_function)(
        ddtrace_user_req_listeners *nonnull self,
        zend_object *nonnull span, zval *nonnull blocking_function);
    void (*nullable delete)(ddtrace_user_req_listeners *nonnull self);
};
bool dd_trace_user_req_add_listeners(
    ddtrace_user_req_listeners *nonnull listeners);

zend_string *nullable dd_ip_extraction_find(zval *nonnull server);

typedef enum {
    DDTRACE_METRIC_TYPE_GAUGE,
    DDTRACE_METRIC_TYPE_COUNT,
    DDTRACE_METRIC_TYPE_DISTRIBUTION,
} ddtrace_metric_type;

typedef enum {
    DDTRACE_METRIC_NAMESPACE_TRACERS,
    DDTRACE_METRIC_NAMESPACE_PROFILERS,
    DDTRACE_METRIC_NAMESPACE_RUM,
    DDTRACE_METRIC_NAMESPACE_APPSEC,
    DDTRACE_METRIC_NAMESPACE_IDE_PLUGINS,
    DDTRACE_METRIC_NAMESPACE_LIVE_DEBUGGER,
    DDTRACE_METRIC_NAMESPACE_IAST,
    DDTRACE_METRIC_NAMESPACE_GENERAL,
    DDTRACE_METRIC_NAMESPACE_TELEMETRY,
    DDTRACE_METRIC_NAMESPACE_APM,
    DDTRACE_METRIC_NAMESPACE_SIDECAR,
} ddtrace_metric_ns;

extern void (*nullable ddtrace_metric_register_buffer)(
    zend_string *nonnull name, ddtrace_metric_type type, ddtrace_metric_ns ns);
extern bool (*nullable ddtrace_metric_add_point)(zend_string *nonnull name,
    double value, zend_string *nonnull tags);
