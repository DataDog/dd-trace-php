<?php

// Test to verify that allocation and time profiling can coexist and that
// the piggybacking optimization works (where time samples are taken during
// allocation samples when interrupt_count > 0).
//
// Note: Piggybacking is opportunistic and happens when an allocation sample
// occurs while a time interrupt is pending. PHP checks interrupts frequently
// (at loop boundaries, function calls, etc.), so the window is narrow. This
// test just verifies the mechanism works, not that it happens often.

function allocate_large() {
    // Large allocation to trigger sampling
    $data = str_repeat("a", 1024 * 200_000); // ~200MB
    return strlen($data);
}

function allocate_medium() {
    // Medium allocation to trigger sampling
    $data = str_repeat("b", 1024 * 100_000); // ~100MB
    return strlen($data);
}

function main() {
    $duration = $_ENV["EXECUTION_TIME"] ?? 10;
    $end = microtime(true) + $duration;

    while (microtime(true) < $end) {
        // Just allocate repeatedly. Over time, some allocations will happen
        // to occur while a time interrupt is pending (set by the profiler
        // thread every ~10ms), exercising the piggybacking code path.
        allocate_large();
        allocate_medium();
    }
}

main();
