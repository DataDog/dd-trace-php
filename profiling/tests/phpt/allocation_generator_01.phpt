--TEST--
[profiling] profiling should not crash during a `ZEND_GENERATOR_CREATE`
--DESCRIPTION--
We found a segfault when allocation profiling triggers during a
`ZEND_GENERATOR_CREATE`. This is due to a missing `SAVE_OPLINE()` in
https://heap.space/xref/PHP-7.4/Zend/zend_vm_execute.h#1790. This missing
`SAVE_OPLINE()` was added in PHP 8.0.26 with the commit
https://github.com/php/php-src/commit/26c7c82d32dad841dd151ebc6a31b8ea6f93f94a
where also a test was added, that we "borrowed" from that commit.

Note: this does not trigger with PHP 7 when the tracer is enabled, as the tracer
restores the opline in a opcode handler!
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    die("skip: test requires datadog-profiling");
if (PHP_VERSION_ID <= 80010)
    die("skip: PHP is buggy");
?>
--ENV--
DD_PROFILING_ALLOCATION_SAMPLING_DISTANCE=1
--INI--
memory_limit=16m
--FILE--
<?php
function a() {
    yield from a();
}
foreach(a() as $v);
?>
--EXPECTF--
Fatal error: Allowed memory size of %d bytes exhausted %s
