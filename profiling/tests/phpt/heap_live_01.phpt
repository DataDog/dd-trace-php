--TEST--
[profiling] heap live profiling tracks allocations
--DESCRIPTION--
Verify that heap live profiling tracks allocations that stay alive.
Uses trace log level to verify allocations are being tracked.
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

// Allocate some memory that stays alive until end of script
// This should be tracked by heap-live profiling
$data = str_repeat('x', 1024);

echo "Done.";

?>
--EXPECTREGEX--
.*Tracked allocation at 0x[0-9a-f]+ \(\d+ bytes\) for batched heap-live emission.*
.*Done\..*
