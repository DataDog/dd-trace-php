--TEST--
Test memory limit returning correct values
--INI--
memory_limit=100
--FILE--
<?php
echo dd_trace_dd_get_memory_limit() . PHP_EOL;

?>
--EXPECT--
FUNCTION
