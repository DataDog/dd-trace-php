// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2026 Datadog, Inc.

// Span reporting, best effort. The tags go on the request's root span: the one
// appsec's request lifecycle holds, else the tracer's active root span. There
// is none during the startup parse (before ddtrace's RINIT), nor once the
// tracer has serialized the trace (after its RSHUTDOWN); the error is then
// reported only by the deferred metric (block) or not at all (monitor, see
// Coverage: Monitor mode in php2shell_mitigation.c). A hard exit is never
// tagged: the trace is not sent. Tags are added, never replaced, so the first
// error of the request wins. Setting one also keeps the trace (user keep,
// mechanism ASM), as an appsec event does. Called while the error is being
// raised: under the finite limit of a deferred error, or, for a native one,
// with zend_mm's overflow flag set, so the allocations here do not bail out
// again (see Coverage: Monitor mode for an out-of-memory error).

#include "p2s_report.h"

#include <php.h>
#include <string.h>

#include "../ddappsec.h" // TSRMLS_CACHE as local-dynamic: no STATIC_TLS
#include "../ddtrace.h"
#include "../request_lifecycle.h"

#define DD_TAG_DEFERRED "_dd.appsec.php2shell.deferred"
#define DD_TAG_MEMORY_ERROR "_dd.appsec.php2shell.memory_error"
#define DD_TAG_PHASE "_dd.appsec.php2shell.phase"

static zend_object *nullable _root_span(void);
static bool _add_tag(zend_object *nonnull span, const char *nonnull tag,
    size_t tag_len, const char *nonnull value);

void dd_p2s_report_deferred(dd_deferred_phase phase)
{
    if (phase == DD_PHASE_STARTUP) {
        return;
    }
    zend_object *span = _root_span();
    if (span == NULL) {
        return;
    }
    if (_add_tag(span, ZEND_STRL(DD_TAG_DEFERRED), dd_p2s_phase_name(phase))) {
        dd_trace_emit_asm_event();
    }
}

// Whatever the phase: phase startup includes the module RINITs (ddtrace's
// creates the root span), so only the startup parse before them has no span.
void dd_p2s_report_memory_error(
    dd_p2s_memory_error kind, dd_deferred_phase phase)
{
    zend_object *span = _root_span();
    if (span == NULL) {
        return;
    }
    const char *kind_name =
        kind == DD_P2S_MEMORY_LIMIT ? "memory_limit" : "out_of_memory";
    if (_add_tag(span, ZEND_STRL(DD_TAG_MEMORY_ERROR), kind_name)) {
        _add_tag(span, ZEND_STRL(DD_TAG_PHASE), dd_p2s_phase_name(phase));
        dd_trace_emit_asm_event();
    }
}

static zend_object *nullable _root_span(void)
{
    zend_object *span = dd_req_lifecycle_get_cur_span();
    return span != NULL ? span : dd_trace_get_active_root_span();
}

// Whether the tag was added (false if the span already has it).
static bool _add_tag(zend_object *nonnull span, const char *nonnull tag,
    size_t tag_len, const char *nonnull value)
{
    return dd_trace_span_add_tag_str(span, tag, tag_len, value, strlen(value));
}
