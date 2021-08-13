--TEST--
Request init hook ignores exceptions
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Test requires internal spans'); ?>
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
Successfully triggered flush with trace of size 1
