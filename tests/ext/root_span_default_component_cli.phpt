--TEST--
CLI root spans get no default component
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump(isset($spans[0]["component"]), isset($spans[0]["meta"]["component"]));
?>
--EXPECT--
bool(false)
bool(false)
