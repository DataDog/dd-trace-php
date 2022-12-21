--TEST--
Exceptions from original call are passed to tracing closure
--FILE--
<?php
use DDTrace\SpanData;

function testExceptionIsNull()
{
    echo "testExceptionIsNull()\n";
}

function testExceptionIsPassed()
{
    echo "testExceptionIsPassed()\n";
    throw new Exception('Oops!');
}

DDTrace\trace_function('testExceptionIsNull', function (SpanData $span, array $args, $retval, $ex) {
    $span->name = 'TestNull';
    var_dump($ex === null);
});

DDTrace\trace_function('testExceptionIsPassed', function (SpanData $span, array $args, $retval, $ex) {
    $span->name = 'TestEx';
    var_dump($ex instanceof Exception);
});

testExceptionIsNull();
try {
    testExceptionIsPassed();
} catch (Exception $e) {
    //
}

array_map(function($span) {
    echo $span['name'];
    if (isset($span['meta']['error.message'])) {
        echo ' with exception: ' . $span['meta']['error.message'];
    }
    echo PHP_EOL;
}, dd_trace_serialize_closed_spans());
?>
--EXPECTF--
testExceptionIsNull()
bool(true)
testExceptionIsPassed()
bool(true)
TestEx with exception: Uncaught Exception: Oops! in %s:%d
TestNull
