--TEST--
Exceptions from user error handler are tracked for instrumented internal functions
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip: PHP 5.4 not supported'); ?>
<?php if (PHP_VERSION_ID >= 70000) die('skip: legacy test for old exception handling'); ?>
--ENV--
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=chmod
--FILE--
<?php
class FooErrorHandler
{
    public static function handleError($errno, $errstr, $errfile, $errline)
    {
        throw new Exception($errstr);
    }
}

set_error_handler('FooErrorHandler::handleError');

DDTrace\trace_function('chmod', function() {});

try {
    var_dump(chmod('php://foo', 0644));
} catch (Exception $e) {
    $spans = dd_trace_serialize_closed_spans();
    echo 'Spans count: ' . count($spans) . PHP_EOL;

    $span = $spans[0];
    echo 'error: ' . $span['error'] . PHP_EOL;
    echo 'error.type: ' . $span['meta']['error.type'] . PHP_EOL;
    echo 'error.msg: ' . $span['meta']['error.msg'] . PHP_EOL;
    echo 'Has error.stack: ' . isset($span['meta']['error.stack']) . PHP_EOL;
}
?>
--EXPECT--
Spans count: 1
error: 1
error.type: Exception
error.msg: chmod(): Can not call chmod() for a non-standard stream
Has error.stack: 1
