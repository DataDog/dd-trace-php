--TEST--
Do not prepend request hook if offending module has been detected
--INI--
ddtrace.request_init_hook=tests/ext/simple_sanity_check.phpt
ddtrace.internal_blacklisted_modules_list=ddtrace,some_other_module
--FILE--
<?php
echo "Request start" . PHP_EOL;

?>
--EXPECT--
Found blacklisted module: ddtrace, disabling conflicting functionality
Request start
