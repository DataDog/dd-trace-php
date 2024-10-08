--TEST--
The return value is null when an exception is thrown in the original call
--DESCRIPTION--
We enable debug mode to ensure this does not raise an "Undefined variable" E_NOTICE in the tracing closure
https://github.com/DataDog/dd-trace-php/issues/788
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
--FILE--
<?php
use DDTrace\SpanData;

function foo()
{
    throw new Exception('Oops!');
    return 42;
}

DDTrace\trace_function('foo', function (SpanData $span, array $args, $retval, $ex) {
    var_dump($ex instanceof Exception);
    var_dump($retval);
});

try {
    foo();
} catch (Exception $e) {
    echo $e->getMessage() . PHP_EOL;
}
?>
--EXPECTF--
bool(true)
NULL
Oops!
[ddtrace] [info] Flushing trace of size 2 to send-queue for %s
