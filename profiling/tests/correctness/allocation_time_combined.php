<?php

// Test to verify that allocation and time profiling can coexist and that
// the piggybacking optimization works (where time samples are taken during
// allocation samples when interrupt_count > 0).
//
// This test uses str_replace, a frameless function (PHP 8.4+) which allocates
// large amounts of memory, increasing the likelihood of capturing piggybacked
// samples. Frameless functions don't handle VM interrupts, making them ideal
// for demonstrating the piggybacking optimization.

function main() {
    $duration = $_ENV["EXECUTION_TIME"] ?? 10;
    $end = microtime(true) + $duration;

    while (microtime(true) < $end) {
        // str_replace is frameless in PHP 8.4+ and allocates a new string
        $xs = str_repeat("x", 10_000_000); // 10MB source
        $ys = str_replace("x", "y", $xs); // 10MB allocation in frameless function
        $zs = str_replace("y", "z", $ys); // 10MB allocation in frameless function
        strlen($zs); // Use the result to prevent optimization
    }
}

main();
