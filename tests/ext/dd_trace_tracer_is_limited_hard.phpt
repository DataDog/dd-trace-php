--TEST--
dd_trace_tracer_is_limited() limits the tracer with a hard span limit
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
--ENV--
DD_TRACE_SPANS_LIMIT=1000
--FILE--
<?php

function my_array_sum(... $args) {
    return \array_sum(... $args);
}

dd_trace('my_array_sum', function () {
    dd_trace_push_span_id();
    dd_trace_pop_span_id();
    return dd_trace_forward_call();
});

var_dump(dd_trace_tracer_is_limited());
for ($i = 0; $i < 999; $i++) {
    my_array_sum([]);
}
var_dump(dd_trace_tracer_is_limited());
my_array_sum([]);
var_dump(dd_trace_tracer_is_limited());
/*
 * Ensure lots more calls do not cause a VM stack overflow.
 * This would cause an abort() in debug builds if
 * zend_call_function() is called too many times from the
 * custom opcode handler.
 */
for ($i = 0; $i < 500000; $i++) {
    my_array_sum([]);
}
var_dump(dd_trace_tracer_is_limited());
?>
--EXPECT--
bool(false)
bool(false)
bool(true)
bool(true)
