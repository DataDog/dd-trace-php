--TEST--
Exceptions from original call are passed to tracing closure (PHP 7)
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip PHP 5 tested in separate test'); ?>
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

dd_trace_function('testExceptionIsNull', function (SpanData $span, array $args, $retval, $ex) {
    $span->name = 'TestNull';
    var_dump($ex === null);
});

dd_trace_function('testExceptionIsPassed', function (SpanData $span, array $args, $retval, $ex) {
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
    if (isset($span['meta']['error.msg'])) {
        echo ' with exception: ' . $span['meta']['error.msg'];
    }
    echo PHP_EOL;
}, dd_trace_serialize_closed_spans());
?>
--EXPECT--
testExceptionIsNull()
bool(true)
testExceptionIsPassed()
bool(true)
TestEx with exception: Oops!
TestNull
