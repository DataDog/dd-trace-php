--TEST--
dd_trace_closed_spans_count() tracks closed spans from userland and C-level
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
var_dump(dd_trace_closed_spans_count());

dd_trace_function('array_sum', function () {});
array_sum([1, 2, array_sum([2, 3])]);
var_dump(dd_trace_closed_spans_count());

dd_trace_push_span_id();
dd_trace_pop_span_id();
echo "Simulated open & close of userland span\n";
var_dump(dd_trace_closed_spans_count());

function foo () {}
dd_trace_function('foo', function () {
    echo "Span not closed yet\n";
    var_dump(dd_trace_closed_spans_count());
});
foo();
var_dump(dd_trace_closed_spans_count());

dd_trace_serialize_closed_spans();
echo "Simulated flush\n";

var_dump(dd_trace_closed_spans_count());
?>
--EXPECT--
int(0)
int(2)
Simulated open & close of userland span
int(3)
Span not closed yet
int(3)
int(4)
Simulated flush
int(0)
