--TEST--
[Prehook] Tracing closure does not have access to thrown exception
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
