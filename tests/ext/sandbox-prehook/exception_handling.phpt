--TEST--
[Prehook Regression] Exceptions get attached to spans
--INI--
; for PHP 7.4+ we want to ensure that even if args are present that we don't print them
zend.exception_ignore_args=Off
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Prehook not supported on PHP 5'); ?>
--FILE--
<?php

function outer() {
    inner('datadog');
}
function inner($message) {
    throw new Exception($message);
}

DDTrace\trace_function("outer", ['prehook' => function() {}]);
DDTrace\trace_function("inner", ['prehook' => function() {}]);

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
Exception msg: Uncaught Exception: datadog in %s:%d
Exception stack:
#0 %s: inner()
#1 %s: outer()
#2 {main}
error: 1
Exception type: Exception
Exception msg: Uncaught Exception: datadog in %s:%d
Exception stack:
#0 %s: inner()
#1 %s: outer()
#2 {main}
