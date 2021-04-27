--TEST--
The original auto_prepend_file will be included even when ddtrace is disabled from INI
--INI--
auto_prepend_file={PWD}/auto_prepend_file.inc
ddtrace.disable=1
ddtrace.request_init_hook={PWD}/../includes/request_init_hook.inc
--FILE--
<?php
echo 'Done.' . PHP_EOL;
?>
--EXPECT--
Original auto_prepend_file
Done.
