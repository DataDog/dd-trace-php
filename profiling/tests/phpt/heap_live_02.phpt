--TEST--
[profiling] heap live profiling untracks freed allocations
--DESCRIPTION--
Verify that heap live profiling untracks allocations when they are freed.
Uses trace log level to verify allocations are being untracked.
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
?>
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_ALLOCATION_ENABLED=yes
DD_PROFILING_HEAP_LIVE_ENABLED=yes
DD_PROFILING_ALLOCATION_SAMPLING_DISTANCE=1
DD_PROFILING_LOG_LEVEL=trace
DD_PROFILING_EXPERIMENTAL_CPU_TIME_ENABLED=no
--INI--
opcache.jit=off
--FILE--
<?php

// Allocate memory in a function scope so it gets freed when function returns
function allocate_and_free() {
    $data = str_repeat('y', 2048);
    // $data goes out of scope here and should be freed
}

allocate_and_free();

// Force garbage collection to ensure memory is freed
gc_collect_cycles();

echo "Done.";

?>
--EXPECTREGEX--
.*Tracked allocation at 0x[0-9a-f]+ \(\d+ bytes\) for batched heap-live emission.*
.*Untracked freed allocation at 0x[0-9a-f]+ \(\d+ bytes\).*
.*Done\..*
