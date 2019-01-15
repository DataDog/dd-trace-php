--TEST--
Prepend PHP code before the processing takes place
--INI--
ddtrace.request_init_hook=tests/ext/simple_sanity_check.phpt
--FILE--
<?php
echo "Request start" . PHP_EOL;

?>
--EXPECTREGEX--
.-TEST--
Simple sanity check, used in run_file_before_request_handling.phpt test
.-FILE--
Check
.-EXPECT--
Check
Request start
