--TEST--
The original auto_prepend_file will be included after the request init hook
--INI--
auto_prepend_file={PWD}/auto_prepend_file.inc
ddtrace.request_init_hook={PWD}/../includes/request_init_hook.inc
--FILE--
<?php
echo 'Done.' . PHP_EOL;
?>
--EXPECT--
Calling ddtrace_init()...
Called dd_init.php
Original auto_prepend_file
Done.
