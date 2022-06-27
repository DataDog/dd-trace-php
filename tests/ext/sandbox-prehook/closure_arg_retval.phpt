--TEST--
[Prehook] Tracing closure does not have access to return value
--FILE--
<?php
use DDTrace\SpanData;

DDTrace\trace_function('foo', [
    'prehook' => function (SpanData $span, array $args, $retval) {
        var_dump($retval);
    }
]);

function foo($a) {
    return 'foo(' . $a . ')';
}

echo foo('bar') . PHP_EOL;
?>
--EXPECT--
NULL
foo(bar)
