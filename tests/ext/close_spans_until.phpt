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
[ddtrace] [span] Encoding span %s: trace_id=%s, name='close_spans_until.php', service='close_spans_until.php', resource: 'close_spans_until.php', type 'cli' with tags: _dd.p.dm='-0', _dd.p.tid='%s', runtime-id='%s'; and metrics: _dd.agent_psr='1', _sampling_priority_v1='1', php.compilation.total_time_ms='%f', php.memory.peak_real_usage_bytes='%d', php.memory.peak_usage_bytes='%d', process_id='%d'
[ddtrace] [span] Encoding span %s: trace_id=%s, name='traced', service='close_spans_until.php', resource: 'traced', type 'cli' with tags: -; and metrics: -
[ddtrace] [span] Encoding span %s: trace_id=%s, name='', service='close_spans_until.php', resource: '', type 'cli' with tags: -; and metrics: -
[ddtrace] [span] Encoding span %s: trace_id=%s, name='', service='close_spans_until.php', resource: '', type 'cli' with tags: -; and metrics: -
[ddtrace] [span] Encoding span %s: trace_id=%s, name='', service='close_spans_until.php', resource: '', type 'cli' with tags: -; and metrics: -
[ddtrace] [span] Encoding span %s: trace_id=%s, name='', service='close_spans_until.php', resource: '', type 'cli' with tags: -; and metrics: -
[ddtrace] [span] Encoding span %s: trace_id=%s, name='', service='close_spans_until.php', resource: '', type 'cli' with tags: -; and metrics: -
[ddtrace] [info] Flushing trace of size 7 to send-queue for %s

