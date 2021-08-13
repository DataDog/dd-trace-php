--TEST--
Exceptions get attached to spans
--INI--
; for PHP 7.4+ we want to ensure that even if args are present that we don't print them
zend.exception_ignore_args=Off
--SKIPIF--
<?php if (PHP_VERSION_ID >= 70000) die('skip: legacy test for old exception handling'); ?>
--FILE--
<?php

function outer() {
    inner('datadog');
}
function inner($message) {
    throw new Exception($message);
}

DDTrace\trace_function("outer", function ($span) {
    $span->name = $span->resource = 'outer';
    $span->service = 'phpt';
});
DDTrace\trace_function("inner", function ($span) {
    $span->name = $span->resource = 'inner';
    $span->service = 'phpt';
});

try {
    outer();
} catch (Exception $e) {
    $stack = dd_trace_serialize_closed_spans();
    echo "Stack size: ", count($stack), "\n";

    $span = $stack[0];
    echo "error: ", $span['error'], "\n";
    echo "Exception type: ", $span['meta']['error.type'], "\n";
    echo "Exception msg: ", $span['meta']['error.msg'], "\n";
    echo "Exception stack:\n", $span['meta']['error.stack'], "\n";

    $span = $stack[1];
    echo "error: ", $span['error'], "\n";
    echo "Exception type: ", $span['meta']['error.type'], "\n";
    echo "Exception msg: ", $span['meta']['error.msg'], "\n";
    echo "Exception stack:\n", $span['meta']['error.stack'], "\n";
}

?>
--EXPECTF--
Stack size: 2
error: 1
Exception type: Exception
Exception msg: datadog
Exception stack:
#0 %s: inner()
#1 %s: outer()
#2 {main}
error: 1
Exception type: Exception
Exception msg: datadog
Exception stack:
#0 %s: inner()
#1 %s: outer()
#2 {main}
