--TEST--
Gracefully handle exceptions in auto_prepend_file
--INI--
auto_prepend_file={PWD}/does_not_exist.inc
ddtrace.request_init_hook={PWD}/../includes/request_init_hook.inc
--FILE--
<?php

echo "Unreachable\n";

?>
--EXPECTF--
Calling ddtrace_init()...
Called dd_init.php

Warning: Unknown: %cailed to open stream: No such file or directory in Unknown on line 0

Fatal error:%sFailed opening required %s in Unknown on line 0
