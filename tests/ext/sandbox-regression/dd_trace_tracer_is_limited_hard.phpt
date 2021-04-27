--TEST--
[Sandbox regression] dd_trace_tracer_is_limited() limits the tracer with a hard span limit
--ENV--
DD_TRACE_SPANS_LIMIT=1000
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum
--FILE--
<?php
DDTrace\trace_function('array_sum', function () {});

var_dump(dd_trace_tracer_is_limited());
for ($i = 0; $i < 999; $i++) {
    array_sum([]);
}
var_dump(dd_trace_tracer_is_limited());
array_sum([]);
var_dump(dd_trace_tracer_is_limited());
/*
 * Ensure lots more calls do not cause a VM stack overflow.
 * This would cause an abort() in debug builds if
 * zend_call_function() is called too many times from the
 * custom opcode handler.
 */
for ($i = 0; $i < 500000; $i++) {
    array_sum([]);
}
var_dump(dd_trace_tracer_is_limited());
?>
--EXPECT--
bool(false)
bool(false)
bool(true)
bool(true)
