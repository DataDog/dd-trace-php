<?php

// Test to verify that allocation and time profiling can coexist and that
// the piggybacking optimization works (where time samples are taken during
// allocation samples when interrupt_count > 0).
//
// This test uses frameless functions (PHP 8.4+) which don't handle VM
// interrupts, making them ideal for testing piggybacking: if we found a time
// sample attributed to a frameless function which is a leaf function, then
// most likely it was piggybacking (but we can actually check that memory
// values were set in the sample, we don't have to guess).
//
// Of course, only memory allocations which get sampled will include time, so
// in other cases the time interrupt will "slip" to something later.

function test_frameless_str_replace(): int {
    // Frameless function (PHP 8.4+) - doesn't handle VM interrupts
    static $source = null;
    if ($source === null) {
        $source = str_repeat("x", 50_000_000); // ~50MB
    }
    $result = str_replace("x", "y", $source);
    return strlen($result);
}

function test_frameless_implode(): int {
    // Frameless function - allocates without interrupt handling
    static $arr = null;
    if ($arr === null) {
        $arr = array_fill(0, 100_000, "test");
    }
    $result = implode(",", $arr);
    return strlen($result);
}

function test_string_concat(): int {
    // ZEND_CONCAT opcode - allocates without interrupt check
    $s = str_repeat("x", 10000);
    for ($i = 0; $i < 100; $i++) {
        $s = $s . "y"; // Each concat allocates new string
    }
    return strlen($s);
}

function test_array_init(): int {
    // ZEND_INIT_ARRAY opcode - allocates without interrupt check
    $total = 0;
    for ($i = 0; $i < 1000; $i++) {
        $arr = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $total += count($arr);
    }
    return $total;
}

function main() {
    $duration = $_ENV["EXECUTION_TIME"] ?? 10;
    $end = microtime(true) + $duration;

    $tests = [
        'test_frameless_str_replace',
        'test_frameless_implode',
        'test_string_concat',
        'test_array_init',
    ];

    while (microtime(true) < $end) {
        foreach ($tests as $test) {
            $test();
        }
    }
}

main();
