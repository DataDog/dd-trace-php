--TEST--
Test get_memory_limit() returning correct values for default
--DESCRIPTION--
The minimum memory_limit is 2M starting PHP 8.1, so using 10M to make the 20% buffer ddtrace reserves obvious.
--INI--
memory_limit=10000000
--FILE--
<?php
echo dd_trace_dd_get_memory_limit() . PHP_EOL;

?>
--EXPECT--
8000000
