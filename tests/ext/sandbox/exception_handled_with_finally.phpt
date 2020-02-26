--TEST--
Return value from finally block is passed to tracing closure
--SKIPIF--
<?php if (PHP_VERSION_ID < 70100) die('skip: This causes an unpatched memory leak from php-src on PHP 5.6 and 7.0'); ?>
--FILE--
<?php
use DDTrace\SpanData;

class FooException extends Exception {}

function throwException() {
    throw new FooException('Oops!');
}

function doCatchWithFinally() {
    try {
        throwException();
        return 'You should not see this';
    } catch (FooException $e) {
        return get_class($e) . ' caught';
    } finally {
        return 'Finally retval';
    }
}

dd_trace_function('throwException', function(SpanData $s) {
    $s->name = 'throwException';
});

dd_trace_function('doCatchWithFinally', function(SpanData $s, $a, $retval) {
    $s->name = 'doCatchWithFinally';
    $s->resource = $retval;
});

echo doCatchWithFinally() . PHP_EOL;

array_map(function($span) {
    echo $span['name'];
    echo isset($span['resource']) ? ', ' . $span['resource'] : '';
    echo isset($span['meta']['error.msg']) ? ', ' . $span['meta']['error.msg'] : '';
    echo PHP_EOL;
}, dd_trace_serialize_closed_spans());
?>
--EXPECT--
Finally retval
doCatchWithFinally, Finally retval
throwException, Oops!
