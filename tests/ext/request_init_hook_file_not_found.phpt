--TEST--
Do not fail when PHP code couldn't be loaded
--INI--
ddtrace.request_init_hook=tests/ext/this_file_doesnt_exist.phpt
--FILE--
<?php
echo "Request start" . PHP_EOL;

?>
--EXPECTREGEX--
Request start
