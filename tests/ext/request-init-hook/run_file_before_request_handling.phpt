--TEST--
Prepend PHP code before the processing takes place and do not blacklist functionality on partial match
--INI--
ddtrace.request_init_hook=tests/ext/includes/sanity_check.php
ddtrace.internal_blacklisted_modules_list=ddtrace_its_not,some_other_module

--FILE--
<?php
echo "Request start" . PHP_EOL;

?>
--EXPECT--
Sanity check
Request start
