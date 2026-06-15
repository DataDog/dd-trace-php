// Copyright 2024-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

#include "trace_filter.h"

#include <php.h>
#include <stdbool.h>

#include <components-rs/datadog.h>

#include "ddtrace.h"
#include <ext/ffi_utils.h>
#include <ext/sidecar.h>
#include "span.h"

#ifndef _WIN32
#include <Zend/zend_API.h>
#endif

ZEND_EXTERN_MODULE_GLOBALS(datadog);

// Lookup callback for ddog_check_stats_trace_filter.
// Returns null when the key is not found anywhere on the span.
static const char *ddtrace_root_tag_value(const void *ctx, const char *key, uintptr_t key_len, uintptr_t *out_len) {
    ddtrace_span_data *root = (ddtrace_span_data *)ctx;

    // Check well-known span struct properties first (not in meta).
#define CHECK_PROP(name_lit, prop)                                              \
    if (key_len == sizeof(name_lit) - 1                                         \
        && memcmp(key, name_lit, sizeof(name_lit) - 1) == 0) {                  \
        zval *_pv = &root->prop;                                                \
        ZVAL_DEREF(_pv);                                                        \
        if (Z_TYPE_P(_pv) == IS_STRING) {                                       \
            *out_len = Z_STRLEN_P(_pv);                                         \
            return Z_STRVAL_P(_pv);                                             \
        }                                                                       \
    }

    CHECK_PROP("name",     property_name)
    CHECK_PROP("type",     property_type)
    CHECK_PROP("env",      property_env)
    CHECK_PROP("version",  property_version)
    CHECK_PROP("service",  property_service)
    CHECK_PROP("resource", property_resource)
#undef CHECK_PROP

    // Meta hash: string tags.
    zend_array *meta = ddtrace_property_array(&root->property_meta);
    if (meta) {
        zval *val = zend_hash_str_find(meta, key, key_len);
        if (val && Z_TYPE_P(val) == IS_STRING) {
            *out_len = Z_STRLEN_P(val);
            return Z_STRVAL_P(val);
        }
    }

    // Metrics hash: numeric tags returned as stringified float.
    zend_array *metrics = ddtrace_property_array(&root->property_metrics);
    if (metrics) {
        zval *val = zend_hash_str_find(metrics, key, key_len);
        if (val) {
            ZEND_TLS char metric_buf[32];
            double d = zval_get_double(val);
            int len = snprintf(metric_buf, sizeof(metric_buf), "%g", d);
            *out_len = (uintptr_t)(len > 0 ? len : 0);
            return metric_buf;
        }
    }

    // Meta struct: key-presence only (value is unrepresentable as a string).
    zend_array *meta_struct = ddtrace_property_array(&root->property_meta_struct);
    if (meta_struct && zend_hash_str_exists(meta_struct, key, key_len)) {
        *out_len = 0;
        return "";
    }

    return NULL;
}

bool ddtrace_trace_passes_filter(ddtrace_span_data *span) {
    zval *root_resource_zv = &span->root->property_resource;
    ZVAL_DEREF(root_resource_zv);
    ddog_CharSlice resource = Z_TYPE_P(root_resource_zv) == IS_STRING
        ? dd_zend_string_to_CharSlice(Z_STR_P(root_resource_zv))
        : DDOG_CHARSLICE_C("");
    return ddog_check_stats_trace_filter(resource, span, ddtrace_root_tag_value);
}
