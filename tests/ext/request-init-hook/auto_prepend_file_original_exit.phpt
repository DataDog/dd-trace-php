--TEST--
A call to exit in the original auto_prepend_file will halt the request
--INI--
auto_prepend_file={PWD}/auto_prepend_file_exit.inc
ddtrace.request_init_hook={PWD}/../includes/request_init_hook.inc
--FILE--
<?php
echo 'You should not see this.' . PHP_EOL;
?>
--EXPECT--
Calling ddtrace_init()...
Called dd_init.php
Going to exit now...
