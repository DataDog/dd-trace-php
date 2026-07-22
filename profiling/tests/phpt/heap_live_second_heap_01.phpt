--TEST--
[profiling] heap-live tracked heap handles allocations and reallocations
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400)
    echo "skip: tracked ZendMM heap requires PHP 8.4+\n";
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
?>
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_ALLOCATION_ENABLED=yes
DD_PROFILING_EXPERIMENTAL_HEAP_LIVE_ENABLED=yes
DD_PROFILING_ALLOCATION_SAMPLING_DISTANCE=1
DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED=no
--INI--
opcache.jit=off
--FILE--
<?php

$objects = [];
for ($i = 0; $i < 2048; $i++) {
    $objects[] = new stdClass();
}

$string = '';
for ($i = 0; $i < 64; $i++) {
    $string .= str_repeat('x', 1024);
}

unset($objects, $string);
gc_mem_caches();
echo "Done.\n";

?>
--EXPECT--
Done.
