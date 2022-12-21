--TEST--
Exceptions from user error handler are tracked for instrumented internal functions
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
    echo 'error.message: ' . $span['meta']['error.message'] . PHP_EOL;
    echo 'Has error.stack: ' . isset($span['meta']['error.stack']) . PHP_EOL;
}
?>
--EXPECTF--
Spans count: 1
error: 1
error.type: Exception
error.message: Uncaught Exception: chmod(): %snot call chmod() for a non-standard stream in %s:%d
Has error.stack: 1
