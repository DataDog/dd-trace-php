--TEST--
The request init hook will be run only one time
--INI--
auto_prepend_file={PWD}/../includes/request_init_hook.inc
ddtrace.request_init_hook={PWD}/../includes/request_init_hook.inc
--FILE--
<?php
echo 'Done.' . PHP_EOL;
?>
--EXPECT--
Calling ddtrace_init()...
Called dd_init.php
Calling ddtrace_init()...
Done.
