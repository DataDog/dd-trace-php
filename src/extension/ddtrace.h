// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.
#pragma once

#include "attributes.h"
#include <stdbool.h>
#include <zend.h>

typedef zend_object root_span_t;

void dd_trace_startup(void);
void dd_trace_shutdown(void);

// Returns the tracer version
const char *nullable dd_trace_version(void);
bool dd_trace_is_loaded(void);

// increases the refcount of tag, but not value (like zval_hash_add)
// however, it destroy value if the operation fails (unlike zval_hash_add)
bool dd_trace_root_span_add_tag(zend_string *nonnull tag, zval *nonnull value);

bool dd_trace_root_span_add_tag_str(const char *nonnull tag, size_t tag_len,
    const char *nonnull value, size_t value_len);

// Flush the tracer spans, can be used on RINIT
void dd_trace_close_all_spans_and_flush(void);

// Provides the array zval representing $root_span->meta, if any.
// It is ready for modification, with refcount == 1
zval *nullable dd_trace_root_span_get_meta(void);
zval *nullable dd_trace_root_span_get_metrics(void);
