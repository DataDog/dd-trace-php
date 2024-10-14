--TEST--
Fatal errors are ignored inside a tracing closure (PHP 7+)
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum
DD_APPSEC_ENABLED=0
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
--EXPECTF--
[ddtrace] [warning] Error thrown in ddtrace's closure defined at %s:%d for array_sum(): Call to undefined function this_function_does_not_exist()
int(100)
array_sum
NULL
[ddtrace] [info] No finished traces to be sent to the agent
