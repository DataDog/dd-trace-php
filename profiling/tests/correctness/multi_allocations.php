<?php

function a()
{
    $a = ['a', 'b', 'c', 'd'];
    $b = ['a', 'b', 'c', 'd'];
    array_intersect($a, $b);
}

function b()
{
    $a = ['a', 'b'];
    $b = ['a', 'b'];
    array_intersect($a, $b);
}

function main()
{
    $duration = $_ENV["EXECUTION_TIME"] ?? 10;
    $end = microtime(true) + $duration;
    while (microtime(true) < $end) {
        $start = microtime(true);
        a();
        b();
        $elapsed = microtime(true) - $start;
        // sleep for the remainder to 100 ms
        // so we end up doing 10 iterations per second
        $sleep = (0.1 - $elapsed);
        if ($sleep > 0.0) {
            usleep((int) ($sleep * 1_000_000));
        }
    }
}
main();
