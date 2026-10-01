--TEST--
Web entrypoint root spans default component to the SAPI name
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--GET--
foo=bar
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump(PHP_SAPI, $spans[0]["component"]);
?>
--EXPECT--
string(8) "cgi-fcgi"
string(8) "cgi-fcgi"
