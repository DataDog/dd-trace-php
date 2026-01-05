<?php

// Test to verify that allocation and time samples can be combined
// This exercises the piggybacking optimization where time samples
// are taken during allocation samples when interrupt_count > 0

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

function consume_cpu() {
    // CPU-intensive work to ensure time passes
    $sum = 0;
    for ($i = 0; $i < 100000; $i++) {
        $sum += $i * $i;
    }
    return $sum;
}

function main() {
    $duration = $_ENV["EXECUTION_TIME"] ?? 10;
    $end = microtime(true) + $duration;

    while (microtime(true) < $end) {
        $start = microtime(true);

        // Mix allocations with CPU work
        allocate_large();
        consume_cpu();
        allocate_medium();
        consume_cpu();

        $elapsed = microtime(true) - $start;

        // Sleep to allow time interrupts to accumulate
        // This increases the likelihood of piggybacking
        $sleep = 0.02 - $elapsed; // Target 50 iterations per second
        if ($sleep > 0.0) {
            usleep((int) ($sleep * 1_000_000));
        }
    }
}

main();
