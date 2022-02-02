--TEST--
[PHP 7 generator smoke test] Exceptions are handled from a generator context
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000 || PHP_VERSION_ID >= 80000) die('skip: Test is for PHP 7'); ?>
--ENV--
DD_TRACE_DEBUG=1
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
?>
--EXPECT--
Cannot instrument generators on PHP 7.x
0
1
2
FooException caught
Successfully triggered flush with trace of size 2
