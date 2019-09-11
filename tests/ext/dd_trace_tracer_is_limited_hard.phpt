--TEST--
dd_trace_tracer_is_limited() limits the tracer with a hard span limit
--ENV--
DD_TRACE_SPANS_LIMIT=2
--FILE--
<?php
dd_trace('array_sum', function () {
    dd_trace_push_span_id();
    dd_trace_pop_span_id();
    return dd_trace_forward_call();
});

var_dump(dd_trace_tracer_is_limited());
array_sum([1, 2, array_sum([2, 3])]);
var_dump(dd_trace_tracer_is_limited());
array_sum([4, 5]);
var_dump(dd_trace_tracer_is_limited());
?>
--EXPECT--
bool(false)
bool(true)
bool(true)
