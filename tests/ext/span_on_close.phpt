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
[ddtrace] [span] [%d] Encoding span: trace_id=%s service="%s" name="root span" resource="root span" type="cli" span_id=%d parent_id=%d start=%d duration=%d error=%s kind=%s env="" version="" component="" attributes={%S} links=%d events=%d
[ddtrace] [span] [%d] Encoding span: trace_id=%s service="%s" name="inner span" resource="datadogs are awesome" type="cli" span_id=%d parent_id=%d start=%d duration=%d error=%s kind=%s env="" version="" component="" attributes={%S} links=%d events=%d
[ddtrace] [info] [%d] Flushing v1 trace of size 2 to send-queue for %s
[ddtrace] [info] [%d] No finished traces to be sent to the agent

