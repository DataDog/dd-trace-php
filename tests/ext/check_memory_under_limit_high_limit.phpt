--TEST--
Test dd_trace_check_memory_under_limit() returning correct values
--INI--
memory_limit=10G
--FILE--
<?php
echo dd_trace_check_memory_under_limit() ? 'true' : 'false'. PHP_EOL;

?>
--EXPECT--
true
