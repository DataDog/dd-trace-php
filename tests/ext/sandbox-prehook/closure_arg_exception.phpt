--TEST--
[Prehook] Tracing closure does not have access to thrown exception
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Prehook not supported on PHP 5'); ?>
--FILE--
<?php
use DDTrace\SpanData;

DDTrace\trace_function('foo', [
    'prehook' => function (SpanData $span, array $args, $retval, $ex) {
        var_dump($ex);
    }
]);

function foo($a) {
    throw new Exception('foo error');
    return 'foo(' . $a . ')';
}

try {
    echo foo('bar') . PHP_EOL;
} catch (Exception $e) {
    echo $e->getMessage() . PHP_EOL;
}
?>
--EXPECT--
NULL
foo error
