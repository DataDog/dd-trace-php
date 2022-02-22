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
--EXPECT--
Successfully triggered flush with trace of size 1
int(-1)
No finished traces to be sent to the agent
