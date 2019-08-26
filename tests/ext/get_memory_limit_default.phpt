--TEST--
Test get_memory_limit() returning correct values for default
--INI--
memory_limit=100
ddtrace.request_init_hook="no_request_init_hook"
--FILE--
<?php
echo dd_trace_dd_get_memory_limit() . PHP_EOL;

?>
--EXPECT--
80
