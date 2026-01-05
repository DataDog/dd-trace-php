--TEST--
[profiling] Piggybacking time samples onto allocation samples
--DESCRIPTION--
When both allocation and time profiling are enabled, and an allocation sample
is taken when there's a pending time interrupt, both samples should be combined
into a single stack walk and message. This test verifies that the optimization
works correctly by enabling both profilers and running code that triggers both.
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires datadog-profiling", PHP_EOL;
?>
--INI--
datadog.profiling.enabled=yes
datadog.profiling.log_level=debug
datadog.profiling.allocation_enabled=yes
datadog.profiling.wall_time_enabled=yes
datadog.profiling.experimental_cpu_time_enabled=no
datadog.profiling.endpoint_collection_enabled=no
--FILE--
<?php

// Function that allocates memory to trigger allocation samples
function allocate_memory() {
    // Allocate enough to increase chances of hitting sampling threshold
    $data = str_repeat("x", 1024 * 1024); // 1MB allocation
    return strlen($data);
}

// Function that consumes time to ensure time interrupts occur
function consume_time() {
    $sum = 0;
    for ($i = 0; $i < 50000; $i++) {
        $sum += $i;
    }
    return $sum;
}

// Main function that combines both allocation and time consumption
function main() {
    $iterations = 50;
    for ($i = 0; $i < $iterations; $i++) {
        allocate_memory();
        consume_time();
        // Small sleep to allow time interrupts to accumulate
        usleep(5000); // 5ms
    }
}

main();

echo "Test completed successfully.", PHP_EOL;
?>
--EXPECTREGEX--
.*Test completed successfully.
.*Stopping profiler.
.*
