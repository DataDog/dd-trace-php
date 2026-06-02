// Copyright 2024-Present Datadog, Inc. https://www.datadoghq.com/
// SPDX-License-Identifier: Apache-2.0

#ifndef DD_TRACE_FILTER_H
#define DD_TRACE_FILTER_H

#include "span.h"

/**
 * Check whether the trace should be processed at all (serialized + sent + stats).
 *
 * Applies ignore_resources, filter_tags, and filter_tags_regex configured via the
 * agent /info endpoint against the root span of the trace.  Returns true to keep
 * the trace, false to drop it entirely from the pipeline.
 */
bool ddtrace_trace_passes_filter(ddtrace_span_data *span);

#endif  // DD_TRACE_FILTER_H
