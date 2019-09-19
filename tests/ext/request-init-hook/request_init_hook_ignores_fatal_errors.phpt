--TEST--
Request init hook ignores fatal errors
--INI--
ddtrace.request_init_hook=tests/ext/request-init-hook/raises_fatal_error.php
--FILE--
<?php
echo "Request start" . PHP_EOL;

?>
--EXPECT--
Calling a function that does not exist...
Request start
