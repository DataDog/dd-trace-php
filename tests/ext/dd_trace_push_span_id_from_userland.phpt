--TEST--
Disabled span ID pushing and popping from userland
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
echo dd_trace_push_span_id('42') . PHP_EOL;
echo dd_trace_pop_span_id() . PHP_EOL;
error_reporting(E_ALL);
?>
--EXPECTF--
dd_trace_push_span_id and dd_trace_pop_span_id DEPRECATION %s
0
0
