--TEST--
Request init hook ignores exceptions
--ENV--
DD_TRACE_DEBUG=1
--INI--
ddtrace.request_init_hook={PWD}/throws_exception.php
--FILE--
<?php
echo "Request start" . PHP_EOL;

?>
--EXPECT--
Throwing an exception...
Exception thrown in request init hook: Oops!
Request start
