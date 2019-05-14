--TEST--
Test get_memory_limit() returning correct values
--INI--
memory_limit=300M
--ENV--
DD_TRACE_MEMORY_LIMIT=0%
--FILE--
<?php
echo dd_trace_dd_get_memory_limit() . PHP_EOL;

?>
--EXPECT--
0
