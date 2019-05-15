--TEST--
Test get_memory_limit() returning correct values for default
--INI--
memory_limit=0
--FILE--
<?php
echo dd_trace_dd_get_memory_limit() . PHP_EOL;

?>
--EXPECT--
-1
