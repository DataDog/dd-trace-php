--TEST--
Negative duration for dropped spans
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=info,startup=off
--FILE--
<?php

$outerSpan = DDTrace\start_span();

DDTrace\start_span();
DDTrace\close_span();
var_dump(count(dd_trace_serialize_closed_spans()));

var_dump($outerSpan->getDuration());

?>
--EXPECTF--
int(1)
int(-1)
[ddtrace] [info] No finished traces to be sent to the agent
