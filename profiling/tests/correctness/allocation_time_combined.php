<?php

// Test to verify that allocation and time profiling can coexist and that
// the piggybacking optimization works (where time samples are taken during
// allocation samples when interrupt_count > 0).
//
// This test uses str_repeat which allocates large amounts of memory,
// increasing the likelihood of capturing piggybacked samples.

function main() {
    $duration = $_ENV["EXECUTION_TIME"] ?? 10;
    $end = microtime(true) + $duration;

    while (microtime(true) < $end) {
        // Allocate large strings to trigger allocation sampling
        $s = str_repeat("x", 10_000_000);
        strlen($s); // Use the string to prevent optimization
    }
}

main();
