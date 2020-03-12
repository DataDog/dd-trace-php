--TEST--
Exceptions are handled for the correct catch block
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip: PHP 5.4 not supported'); ?>
--FILE--
<?php
use DDTrace\SpanData;

class FooException extends Exception {}
class BarException extends FooException {}

function throwException() {
    throw new BarException('Oops!');
}

function multiCatch() {
    try {
        throwException();
        return 'You should not see this';
    } catch (DomainException $e) {
        return get_class($e) . ' caught';
    } catch (RuntimeException $e) {
        return get_class($e) . ' caught';
    } catch (FooException $e) {
        return get_class($e) . ' caught';
    } catch (LogicException $e) {
        return get_class($e) . ' caught';
    }
}

function embeddedCatch() {
    try {
        try {
            try {
                throwException();
                return 'You should not see this';
            } catch (LogicException $e) {
                return get_class($e) . ' caught';
            }
        } catch (FooException $e) {
            return get_class($e) . ' caught';
        }
    } catch (DomainException $e) {
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

dd_trace_function('embeddedCatch', function(SpanData $s, $a, $retval) {
    $s->name = 'embeddedCatch';
    $s->resource = $retval;
});

echo multiCatch() . PHP_EOL;
echo embeddedCatch() . PHP_EOL;

array_map(function($span) {
    echo $span['name'];
    echo isset($span['resource']) ? ', ' . $span['resource'] : '';
    echo isset($span['meta']['error.msg']) ? ', ' . $span['meta']['error.msg'] : '';
    echo PHP_EOL;
}, dd_trace_serialize_closed_spans());
?>
--EXPECT--
BarException caught
BarException caught
embeddedCatch, BarException caught
throwException, Oops!
multiCatch, BarException caught
throwException, Oops!
