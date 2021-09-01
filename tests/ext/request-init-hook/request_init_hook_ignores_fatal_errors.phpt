--TEST--
Request init hook ignores fatal errors
--SKIPIF--
<?php if (PHP_VERSION_ID < 50600) die('skip: Cannot recover from fatal errors until PHP 5.6'); ?>
<?php if (PHP_VERSION_ID < 70000) die('skip: Test requires internal spans'); ?>
--ENV--
DD_TRACE_DEBUG=1
--INI--
ddtrace.request_init_hook={PWD}/raises_fatal_error.php
--FILE--
<?php
echo "Request start" . PHP_EOL;

?>
--EXPECTF--
Calling a function that does not exist...
Error %s in request init hook: Call to undefined function this_function_does_not_%s
Request start
Successfully triggered flush with trace of size 1
