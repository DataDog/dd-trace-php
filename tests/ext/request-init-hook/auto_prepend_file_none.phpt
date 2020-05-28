--TEST--
auto_prepend_file set to 'none'
--INI--
auto_prepend_file=none
ddtrace.request_init_hook={PWD}/../includes/request_init_hook.inc
--FILE--
<?php
echo 'Done.' . PHP_EOL;
?>
--EXPECT--
Calling ddtrace_init()...
Called dd_init.php
Done.
