--TEST--
[Sandbox regression] dd_trace_tracer_is_limited() limits the tracer with a hard span limit
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--ENV--
DD_TRACE_SPANS_LIMIT=1000
--INI--
ddtrace.traced_internal_functions=array_sum
--FILE--
<?php
dd_trace_function('array_sum', function () {});

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
