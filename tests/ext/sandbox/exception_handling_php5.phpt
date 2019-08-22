--TEST--
Exceptions get attached to spans
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip: PHP 5.4 not yet supported'); ?>
<?php if (PHP_VERSION_ID >= 70000) die('skip: PHP 5 only test'); ?>
--FILE--
<?php

function outer() {
    inner();
}
function inner() {
    throw new Exception("datadog");
}

dd_trace_function("outer", function() {});
dd_trace_function("inner", function() {});

try {
    outer();
} catch (Exception $e) {
    $stack = dd_trace_serialize_closed_spans();
    echo "Stack size: ", count($stack), "\n";

    $span = $stack[0];
    echo "error: ", $span['error'], "\n";
    echo "Exception name: ", $span['meta']['error.name'], "\n";
    echo "Exception msg: ", $span['meta']['error.msg'], "\n";
    echo "Exception stack:\n", $span['meta']['error.stack'], "\n";

    $span = $stack[1];
    echo "error: ", $span['error'], "\n";
    echo "Exception name: ", $span['meta']['error.name'], "\n";
    echo "Exception msg: ", $span['meta']['error.msg'], "\n";
    echo "Exception stack:\n", $span['meta']['error.stack'], "\n";
}

?>
--EXPECTF--
Stack size: 2
error: 1
Exception name: Exception
Exception msg: datadog
Exception stack:
#0 %s: inner()
#1 %s: outer()
#2 %s: outer()
#3 %s: unknown()
#4 {main}
error: 1
Exception name: Exception
Exception msg: datadog
Exception stack:
#0 %s: inner()
#1 %s: outer()
#2 %s: outer()
#3 %s: unknown()
#4 {main}

