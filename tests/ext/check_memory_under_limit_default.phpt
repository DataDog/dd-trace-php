--TEST--
Test dd_trace_check_memory_under_limit() returning correct values for default
--INI--
memory_limit=100M
--FILE--
<?php
echo dd_trace_check_memory_under_limit() ? 'true' : 'false'. PHP_EOL;

?>
--EXPECT--
true
