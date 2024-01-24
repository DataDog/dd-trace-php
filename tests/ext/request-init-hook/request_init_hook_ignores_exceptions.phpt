--TEST--
Request init hook ignores exceptions
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
--INI--
ddtrace.request_init_hook={PWD}/throws_exception.php
--FILE--
<?php
echo "Request start" . PHP_EOL;

?>
--EXPECTF--
Throwing an exception...
[ddtrace] [warning] Exception thrown in request init hook: Oops!
Request start
[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
