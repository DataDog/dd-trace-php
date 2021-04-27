--TEST--
Exceptions are handled from a generator context
--SKIPIF--
<?php if (PHP_VERSION_ID < 70100) die('skip: Generators are partially supported on PHP 7.1+'); ?>
<?php if (PHP_VERSION_ID >= 80000) die('skip: Generators are fully supported on PHP 8+'); ?>
--FILE--
<?php
use DDTrace\SpanData;

class FooException extends Exception {}

function maybeThrowException() {
    for ($i = 0; $i <= 3; $i++) {
        if ($i === 3) {
            // TODO Figure out this black hole
            // The span closes on the first yield so there
            // is no span to attach the exception to
            throw new FooException('Oops!');
        }
        yield $i;
    }
}

function doSomething() {
    try {
        $generator = maybeThrowException();
        foreach ($generator as $value) {
            echo $value . PHP_EOL;
        }
        return 'You should not see this';
    } catch (FooException $e) {
        return get_class($e) . ' caught';
    }
}

DDTrace\trace_function('maybeThrowException', function(SpanData $s, $a, $retval) {
    $s->name = 'maybeThrowException';
    $s->resource = $retval;
});

DDTrace\trace_function('doSomething', function(SpanData $s, $a, $retval) {
    $s->name = 'doSomething';
    $s->resource = $retval;
});

echo doSomething() . PHP_EOL;

array_map(function($span) {
    echo $span['name'];
    echo isset($span['resource']) ? ', ' . $span['resource'] : '';
    echo isset($span['meta']['error.msg']) ? ', ' . $span['meta']['error.msg'] : '';
    echo PHP_EOL;
}, dd_trace_serialize_closed_spans());
?>
--EXPECT--
0
1
2
FooException caught
doSomething, FooException caught
maybeThrowException, 0
