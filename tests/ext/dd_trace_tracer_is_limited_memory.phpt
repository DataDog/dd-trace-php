--TEST--
dd_trace_tracer_is_limited() limits the tracer with a memory limit
--ENV--
DD_TRACE_MEMORY_LIMIT=1M
--SKIPIF--
<?php if (getenv('USE_ZEND_ALLOC') === '0') die('skip Zend MM must be enabled'); ?>
--FILE--
<?php
var_dump(dd_trace_tracer_is_limited());
$a = str_repeat('a', 2 * 1024 * 1024); // 2MB
var_dump(dd_trace_tracer_is_limited());
?>
--EXPECT--
bool(false)
bool(true)
