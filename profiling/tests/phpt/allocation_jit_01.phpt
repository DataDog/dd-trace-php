--TEST--
[profiling] Allocation profiling should not crash when run with `USE_ZEND_ALLOC=0`
--DESCRIPTION--
When running PHP with `USE_ZEND_ALLOC=0` and disabled allocation profiling
(either explicit disabled or due to the JIT bug), PHP will install the system
allocator in https://heap.space/xref/PHP-8.2/Zend/zend_alloc.c?r=4553258d#2895-2918
and set the `AG(heap)->use_custom_heap` to `ZEND_MM_CUSTOM_HEAP_STD` (which is 1),
so future calls to `is_zend_mm()` will return false which might lead to a situation
where we assume we are hooked into, while we are not.
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires datadog-profiling", PHP_EOL;
if (PHP_VERSION_ID < 80000)
    echo "skip: JIT requires PHP >= 8.0", PHP_EOL;
?>
--ENV--
USE_ZEND_ALLOC=0
--INI--
datadog.profiling.enabled=yes
datadog.profiling.log_level=debug
datadog.profiling.allocation_enabled=yes
datadog.profiling.experimental_cpu_time_enabled=no
zend_extension=opcache
opcache.enable_cli=1
opcache.jit=tracing
opcache.jit_buffer_size=4M
--FILE--
<?php
echo "Done.", PHP_EOL;
?>
--EXPECTREGEX--
.*Done.
.*Stopping profiler.
.*
