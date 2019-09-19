--TEST--
Do not fail when PHP code couldn't be loaded
--INI--
ddtrace.request_init_hook=tests/ext/request-init-hook/this_file_doesnt_exist.php
--FILE--
<?php
echo "Request start" . PHP_EOL;

?>
--EXPECT--
Request start
