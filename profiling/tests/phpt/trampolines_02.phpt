--TEST--
[profiling] use-after-free for inspecting after closure trampoline is called.
--DESCRIPTION--
The code for Closure::__invoke will free the `execute_data->func` before it
returns, but it does not set it to null, except in debug builds:
https://heap.space/xref/PHP-8.2/Zend/zend_closures.c?r=af2110e6#60-63

Our zend_execute_internal hook inspected the func after the call has been made,
potentially triggering the issue. This test will likely only fail under asan.
It's unclear how the customer also got a crash out of it.
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
if (PHP_VERSION_ID < 80200)
    echo "skip: test requires PHP 8.2+\n";
?>
--INI--
datadog.profiling.enabled=1
datadog.profiling.experimental_allocation_enabled=0
--FILE--
<?php

$closure = Closure::fromCallable('sleep');
$closure->__invoke(1);
echo "Done.\n";
?>
--EXPECT--
Done.
