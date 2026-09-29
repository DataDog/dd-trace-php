<?php

function allocation_sampling_distance_probe()
{
    // 104 + align8(24 + 1) = 136 bytes are requested (align8(24 + 104 + 1) is
    // also 136), which Zend MM rounds up to its 160 byte bin.
    $value = str_repeat('a', 104);
    unset($value);
}

function main()
{
    // At sampling distance 1, each call must contribute one allocation.
    for ($i = 0; $i < 100; $i++) {
        allocation_sampling_distance_probe();
    }
}

// Initialize allocation profiling before the measured stack is entered.
// This call has no main frame, so it is excluded from the expected count.
allocation_sampling_distance_probe();
main();
