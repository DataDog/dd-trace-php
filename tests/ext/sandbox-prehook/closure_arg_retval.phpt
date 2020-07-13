--TEST--
[Prehook] Tracing closure does not have access to return value
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Prehook not supported on PHP 5'); ?>
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
