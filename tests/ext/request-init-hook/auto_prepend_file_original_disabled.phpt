--TEST--
The original auto_prepend_file will be included even when ddtrace is disabled from ENV
--DESCRIPTION--
Note: this should have the same expected output as the auto_prepend_file_original_disabled_ini.phpt
test. Once the enable/disable functionality is refactored, this test should fail with the same
output as the other test and can be updated.
--ENV--
DD_TRACE_ENABLED=0
--INI--
auto_prepend_file={PWD}/auto_prepend_file.inc
ddtrace.request_init_hook={PWD}/../includes/request_init_hook.inc
--FILE--
<?php
echo 'Done.' . PHP_EOL;
?>
--EXPECT--
Calling ddtrace_init()...
Original auto_prepend_file
Done.
