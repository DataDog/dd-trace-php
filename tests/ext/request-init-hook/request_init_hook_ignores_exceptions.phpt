--TEST--
Request init hook ignores exceptions
--INI--
ddtrace.request_init_hook=tests/ext/request-init-hook/throws_exception.php
--FILE--
<?php
echo "Request start" . PHP_EOL;

?>
--EXPECT--
Throwing an exception...
Request start
