--TEST--
Do not fail when PHP code couldn't be loaded
--ENV--
DD_TRACE_DEBUG=1
--INI--
ddtrace.request_init_hook={PWD}/this_file_doesnt_exist.php
--FILE--
<?php
echo "Request start" . PHP_EOL;

?>
--EXPECTF--
Cannot open request init hook; file does not exist: '%s/this_file_doesnt_exist.php'
Request start
