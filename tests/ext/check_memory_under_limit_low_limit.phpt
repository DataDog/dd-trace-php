--TEST--
Test dd_trace_check_memory_under_limit() returning correct values
--SKIPIF--
<?php
if (getenv("USE_ZEND_ALLOC") !== "1")
    print "skip Need Zend MM enabled";
?>
--INI--
memory_limit=100k
--FILE--
<?php
echo dd_trace_check_memory_under_limit() ? 'true' : 'false'. PHP_EOL;

?>
--EXPECT--
false
