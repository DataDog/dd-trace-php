--TEST--
Fatal errors are ignored inside a tracing closure (PHP 7+)
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip Fatal errors cannot be ignored in PHP 5'); ?>
--ENV--
DD_TRACE_DEBUG=1
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum
--FILE--
<?php
DDTrace\trace_function('array_sum', function (DDTrace\SpanData $span) {
    $span->name = 'array_sum';
    this_function_does_not_exist();
});

var_dump(array_sum([1, 99]));

array_map(function($span) {
    echo $span['name'] . PHP_EOL;
}, dd_trace_serialize_closed_spans());
var_dump(error_get_last());
?>
--EXPECT--
Error thrown in ddtrace's closure for array_sum(): Call to undefined function this_function_does_not_exist()
int(100)
array_sum
NULL
No finished traces to be sent to the agent
