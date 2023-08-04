<?php

function a()
{
    str_repeat("a", 1024 * 12000);
}

function b()
{
    str_repeat("a", 1024 * 6000);
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
            usleep($sleep * 1_000_000);
        }
    }
}
main();
