--TEST--
Disabled span ID pushing and popping from userland
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
echo dd_trace_push_span_id('42') . PHP_EOL;
echo dd_trace_pop_span_id() . PHP_EOL;
?>
--EXPECTF--
dd_trace_push_span_id and dd_trace_pop_span_id DEPRECATION %s
0
0
