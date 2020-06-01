--TEST--
[Prehook Regression] dd_trace_function() can trace internal functions with internal spans
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Prehook not supported on PHP 5'); ?>
--INI--
ddtrace.traced_internal_functions=array_sum
--FILE--
<?php
use DDTrace\SpanData;

var_dump(dd_trace_function('array_sum', ['prehook' => function (SpanData $span) {
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
