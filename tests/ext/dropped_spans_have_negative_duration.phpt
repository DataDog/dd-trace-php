--TEST--
Negative duration for dropped spans
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_DEBUG=1
--FILE--
<?php

$outerSpan = DDTrace\start_span();

DDTrace\start_span();
DDTrace\close_span();
DDTrace\flush();

var_dump($outerSpan->getDuration());

?>
--EXPECTF--
Flushing trace of size 1 to send-queue for %s
int(-1)
No finished traces to be sent to the agent
