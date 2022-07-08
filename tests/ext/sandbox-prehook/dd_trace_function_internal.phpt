--TEST--
[Prehook Regression] DDTrace\trace_function() can trace internal functions with internal spans
--ENV--
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum
--FILE--
<?php
use DDTrace\SpanData;

var_dump(DDTrace\trace_function('array_sum', ['prehook' => function (SpanData $span) {
    $span->name = 'ArraySum';
}]));

var_dump(array_sum([1, 3, 5]));

array_map(function($span) {
    echo $span['name'], PHP_EOL;
}, dd_trace_serialize_closed_spans());
?>
--EXPECT--
bool(true)
int(9)
ArraySum
