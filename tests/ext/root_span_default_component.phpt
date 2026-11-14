--TEST--
Web entrypoint root spans default component to "web"
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--GET--
foo=bar
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans()['chunks'][0]['spans'];
var_dump($spans[0]["component"]);
?>
--EXPECT--
string(3) "web"
