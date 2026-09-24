<?php

function allocation_sampling_distance_probe()
{
    $value = str_repeat('a', 87);
    unset($value);
}

function main()
{
    // At sampling distance 1, each call must contribute one allocation.
    // Match the probe's str_repeat stack, not its allocator-dependent byte size.
    for ($i = 0; $i < 100; $i++) {
        allocation_sampling_distance_probe();
    }
}

// Initialize allocation profiling before the measured stack is entered.
// This call has no main frame, so it is excluded from the expected count.
allocation_sampling_distance_probe();
main();
