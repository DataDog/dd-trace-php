--TEST--
Exceptions from user error handler are tracked for instrumented internal functions
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip: PHP 5.4 not supported'); ?>
<?php if (PHP_VERSION_ID < 70000) die('skip: Unaltered VM dispatch required for handling return value on PHP 5'); ?>
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

dd_trace_function('scandir', function() {});

try {
    var_dump(scandir(''));
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
error.msg: scandir(): Directory name cannot be empty
Has error.stack: 1
