--TEST--
Exceptions are handled with multi-catch syntax
--DESCRIPTION--
Even though multi-catch syntax is equivalent to using multiple catch blocks from
the VM perspective, this test exists in case that changes in the future.
--SKIPIF--
<?php if (PHP_VERSION_ID < 70100) die('skip: Multi-catch was added in PHP 7.1'); ?>
--FILE--
<?php
use DDTrace\SpanData;

class FooException extends Exception {}

function throwException() {
    throw new FooException('Oops!');
}

function multiCatch() {
    try {
        throwException();
        return 'You should not see this';
    } catch (DomainException | RuntimeException | FooException | LogicException $e) {
        return get_class($e) . ' caught';
    }
}

dd_trace_function('throwException', function(SpanData $s) {
    $s->name = 'throwException';
});

dd_trace_function('multiCatch', function(SpanData $s, $a, $retval) {
    $s->name = 'multiCatch';
    $s->resource = $retval;
});

echo multiCatch() . PHP_EOL;

array_map(function($span) {
    echo $span['name'];
    echo isset($span['resource']) ? ', ' . $span['resource'] : '';
    echo isset($span['meta']['error.msg']) ? ', ' . $span['meta']['error.msg'] : '';
    echo PHP_EOL;
}, dd_trace_serialize_closed_spans());
?>
--EXPECT--
FooException caught
multiCatch, FooException caught
throwException, Oops!
