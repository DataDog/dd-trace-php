--TEST--
A component set by an integration overrides the web root span default
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--GET--
foo=bar
--FILE--
<?php
$span = DDTrace\start_span();
$span->meta["component"] = "laravel";
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump($spans[0]["component"]);
?>
--EXPECT--
string(7) "laravel"
