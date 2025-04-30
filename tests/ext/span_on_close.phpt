--TEST--
Test onClose SpanData handler
--INI--
datadog.trace.debug=true
datadog.autofinish_spans=true
--FILE--
<?php

DDTrace\active_span()->onClose[] = function($span) {
    $span->name = "root span";
};

$span = DDTrace\start_span();
$span->onClose = [
    function($span) {
        print "First\n";
        $span->name = "inner span";
    },
    function($span) {
        print "Second\n";
        $span->resource = "datadogs are awesome";
    },
];

?>
--EXPECTF--
Second
First
[ddtrace] [span] Encoding span: Span { service: %s, name: root span, resource: root span, type: cli, trace_id: %d, span_id: %d, parent_id: %d, start: %d, duration: %d, error: %d, meta: %a, metrics: %a, meta_struct: %a, span_links: %a, span_events: %a }
[ddtrace] [span] Encoding span: Span { service: %s, name: inner span, resource: datadogs are awesome, type: cli, trace_id: %d, span_id: %d, parent_id: %d, start: %d, duration: %d, error: %d, meta: %a, metrics: %a, meta_struct: %a, span_links: %a, span_events: %a }
[ddtrace] [info] Flushing trace of size 2 to send-queue for %s
[ddtrace] [info] No finished traces to be sent to the agent

