--TEST--
Basic functionality of dd_trace_serialize_trace()
--FILE--
<?php
putenv('APP_ENV=dd_testing');
include __DIR__ . '/../../bridge/dd_wrap_autoloader.php';

$tracer = \DDTrace\GlobalTracer::get();

$encoded = dd_trace_serialize_trace($tracer);
var_dump($encoded);
?>
--EXPECT--
bool(true)
