<?php

const ITERATIONS = 524288;
const SMALL_SIZE = 64 * 1024;
const LARGE_SIZE = 32 * 1024 * 1024;

function allocate_varying_size($size)
{
    $value = str_repeat('x', $size);
    unset($value);
}

function main()
{
    for ($i = 0; $i < ITERATIONS; $i++) {
        // One large allocation per 512 calls, always from the same call site.
        $size = $i % 512 === 0 ? LARGE_SIZE : SMALL_SIZE;
        allocate_varying_size($size);
    }
}

main();
