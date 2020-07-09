--TEST--
Request init hook ignores fatal errors
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip: Cannot recover from fatal errors in PHP 5.4'); ?>
--INI--
ddtrace.request_init_hook=tests/ext/request-init-hook/raises_fatal_error.php
--FILE--
<?php
echo "Request start" . PHP_EOL;

?>
--EXPECT--
Calling a function that does not exist...
Request start
