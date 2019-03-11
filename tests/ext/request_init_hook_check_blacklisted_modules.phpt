--TEST--
Do not prepend request hook if offending module has been detected
--INI--
ddtrace.request_init_hook=tests/ext/simple_sanity_check.phpt
ddtrace.internal_blacklisted_modules_regexp=/ddtrace/
--FILE--
<?php
echo "Request start" . PHP_EOL;

?>
--EXPECT--
Warning: Found blacklisted module: ddtrace, disabling conflicting functionality in Unknown on line 0
Request start
