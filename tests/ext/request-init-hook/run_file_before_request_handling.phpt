--TEST--
Prepend PHP code before the processing takes place and do not blacklist functionality on partial match
--INI--
ddtrace.request_init_hook={PWD}/../includes/sanity_check.php
--FILE--
<?php
echo "Request start" . PHP_EOL;

?>
--EXPECT--
Sanity check
Request start
