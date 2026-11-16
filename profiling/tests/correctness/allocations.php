<?php

function a()
{
    $a = str_repeat("a", 1024 * 12_000);
    // One replacement still allocates a full copy, without per-byte work.
    $a[0] = 'b';
    str_replace('b', 'c', $a);
}

function b()
{
    $a = str_repeat("a", 1024 * 6_000);
    $a[0] = 'b';
    str_replace('b', 'c', $a);
}

function main()
{
    // Fixed work makes allocation totals independent of machine speed.
    for ($i = 0; $i < 512; $i++) {
        a();
        b();
    }
}
main();
