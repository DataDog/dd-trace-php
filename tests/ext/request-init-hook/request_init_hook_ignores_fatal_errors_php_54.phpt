--TEST--
Request init hook ignores fatal errors (PHP 5.4)
--DESCRIPTION--
The SKIPIF hack in raises_fatal_error.php prevents this test from running.
This test can be used to test manually.
--SKIPIF--
<?php if (PHP_VERSION_ID >= 50500) die('skip: Test for PHP 5.4 only'); ?>
--ENV--
DD_TRACE_DEBUG=1
--INI--
ddtrace.request_init_hook={PWD}/raises_fatal_error.php
--FILE--
<?php
echo 'Request start' . PHP_EOL;
?>
--EXPECTF--
Calling a function that does not exist...
Unrecoverable error raised in request init hook: Call to undefined function this_function_does_not_exist() in %s on line %d
