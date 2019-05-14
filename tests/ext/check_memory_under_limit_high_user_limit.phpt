--TEST--
Test dd_trace_check_memory_under_limit() returning correct values
--INI--
memory_limit=1k
--ENV--
DD_TRACE_MEMORY_LIMIT=100M
--FILE--
<?php
echo dd_trace_check_memory_under_limit() ? 'true' : 'false'. PHP_EOL;

?>
--EXPECT--
true
