--TEST--
Request init hook ignores fatal errors
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
Flushing trace of size 1 to send-queue for %s
