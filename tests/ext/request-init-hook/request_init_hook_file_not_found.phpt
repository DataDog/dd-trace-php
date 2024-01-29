--TEST--
Do not fail when PHP code couldn't be loaded
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
--INI--
ddtrace.request_init_hook={PWD}/this_file_doesnt_exist.php
--FILE--
<?php
echo "Request start" . PHP_EOL;

?>
--EXPECTF--
[ddtrace] [warning] Cannot open request init hook; file does not exist: '%s/this_file_doesnt_exist.php'
Request start
[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
