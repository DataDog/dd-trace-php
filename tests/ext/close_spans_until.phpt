--TEST--
Test DDTrace\close_spans_until
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,span=trace,startup=off
--FILE--
<?php

function traced() {
    DDTrace\start_span();
    DDTrace\start_span();
    var_dump(DDTrace\close_spans_until(DDTrace\root_span()));
    var_dump(DDTrace\close_spans_until(null));

    $start = DDTrace\start_span();
    DDTrace\start_span();
    DDTrace\start_span();
    var_dump(DDTrace\close_spans_until($start));
    DDTrace\close_span();
}

DDTrace\trace_function('traced', function() {});
traced();
var_dump(DDTrace\close_spans_until(null));
var_dump(DDTrace\close_spans_until(null));
?>
--EXPECTF--
[ddtrace] [span] Creating new root SpanStack: %d, parent_stack: 0
[ddtrace] [span] Creating new root SpanStack: %d, parent_stack: %d
[ddtrace] [span] Switching to different SpanStack: %d
[ddtrace] [span] Starting new root span: trace_id=%s, span_id=%d, parent_id=0, SpanStack=%d, parent_SpanStack=%d
[ddtrace] [span] Starting new span: trace_id=%s, span_id=%d, parent_id=%d, SpanStack=%d
[ddtrace] [span] Starting new span: trace_id=%s, span_id=%d, parent_id=%d, SpanStack=%d
[ddtrace] [span] Starting new span: trace_id=%s, span_id=%d, parent_id=%d, SpanStack=%d
bool(false)
[ddtrace] [span] Closing span: trace_id=%s, span_id=%d
[ddtrace] [span] Closing span: trace_id=%s, span_id=%d
int(2)
[ddtrace] [span] Starting new span: trace_id=%s, span_id=%d, parent_id=%d, SpanStack=%d
[ddtrace] [span] Starting new span: trace_id=%s, span_id=%d, parent_id=%d, SpanStack=%d
[ddtrace] [span] Starting new span: trace_id=%s, span_id=%d, parent_id=%d, SpanStack=%d
[ddtrace] [span] Closing span: trace_id=%s, span_id=%d
[ddtrace] [span] Closing span: trace_id=%s, span_id=%d
int(2)
[ddtrace] [span] Closing span: trace_id=%s, span_id=%d
[ddtrace] [span] Closing span: trace_id=%s, span_id=%d
[ddtrace] [span] Closing root span: trace_id=%s, span_id=%d
[ddtrace] [span] Switching to different SpanStack: %d
int(1)
int(0)
[ddtrace] [span] Encoding span: Span { service: close_spans_until.php, name: close_spans_until.php, resource: close_spans_until.php, type: cli, trace_id: %d, span_id: %d, parent_id: 0, start: %d, duration: %d, error: 0, meta: %a, metrics: %a, meta_struct: {}, span_links: [], span_events: [] }
[ddtrace] [span] Encoding span: Span { service: close_spans_until.php, name: traced, resource: traced, type: cli, trace_id: %d, span_id: %d, parent_id: %d, start: %d, duration: %d, error: %d, meta: %a, metrics: %a, meta_struct: %a, span_links: %a, span_events: %a }
[ddtrace] [span] Encoding span: Span { service: close_spans_until.php, name: , resource: , type: cli, trace_id: %d, span_id: %d, parent_id: %d, start: %d, duration: %d, error: %d, meta: %a, metrics: %a, meta_struct: %a, span_links: %a, span_events: %a }
[ddtrace] [span] Encoding span: Span { service: close_spans_until.php, name: , resource: , type: cli, trace_id: %d, span_id: %d, parent_id: %d, start: %d, duration: %d, error: %d, meta: %a, metrics: %a, meta_struct: %a, span_links: %a, span_events: %a }
[ddtrace] [span] Encoding span: Span { service: close_spans_until.php, name: , resource: , type: cli, trace_id: %d, span_id: %d, parent_id: %d, start: %d, duration: %d, error: %d, meta: %a, metrics: %a, meta_struct: %a, span_links: %a, span_events: %a }
[ddtrace] [span] Encoding span: Span { service: close_spans_until.php, name: , resource: , type: cli, trace_id: %d, span_id: %d, parent_id: %d, start: %d, duration: %d, error: %d, meta: %a, metrics: %a, meta_struct: %a, span_links: %a, span_events: %a }
[ddtrace] [span] Encoding span: Span { service: close_spans_until.php, name: , resource: , type: cli, trace_id: %d, span_id: %d, parent_id: %d, start: %d, duration: %d, error: %d, meta: %a, metrics: %a, meta_struct: %a, span_links: %a, span_events: %a }
[ddtrace] [info] Flushing trace of size 7 to send-queue for %s
