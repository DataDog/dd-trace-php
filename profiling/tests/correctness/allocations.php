<?php

function a()
{
    $a = str_repeat("a", 1024 * 12_000);
    str_replace('a', 'b', $a);
}

function b()
{
    $a = str_repeat("a", 1024 * 6_000);
    str_replace('a', 'b', $a);
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
