--TEST--
Test get_memory_limit() returning correct values for default
--SKIPIF--
<?php if (PHP_VERSION_ID >= 80100) die('skip: Memory limit cannot be set below 2M beginning PHP 8.1'); ?>
--INI--
memory_limit=0
--FILE--
<?php
echo dd_trace_dd_get_memory_limit() . PHP_EOL;

?>
--EXPECT--
-1
