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
[ddtrace] [span] Encoding span %s name='root span'%sresource: 'root span'%s
[ddtrace] [span] Encoding span %s name='inner span',%sresource: 'datadogs are awesome'%s
[ddtrace] [info] Flushing trace of size 2 to send-queue for %s
[ddtrace] [info] No finished traces to be sent to the agent
