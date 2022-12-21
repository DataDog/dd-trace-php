--TEST--
[regression] The limiter must reset after a flush
--ENV--
DD_TRACE_SPANS_LIMIT=10
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=1
--FILE--
<?php
DDTrace\trace_function('array_sum', function () {});

DDTrace\start_span();

var_dump(dd_trace_tracer_is_limited());
for ($i = 0; $i < 8; $i++) {
    array_sum([]);
}
var_dump(dd_trace_tracer_is_limited());
array_sum([]);
var_dump(dd_trace_tracer_is_limited());
array_sum([]);
var_dump(dd_trace_tracer_is_limited());

DDTrace\close_span(); // flush!

var_dump(dd_trace_tracer_is_limited());
array_sum([]);
var_dump(dd_trace_tracer_is_limited());
?>
--EXPECT--
bool(false)
bool(false)
bool(true)
bool(true)
bool(false)
bool(false)
