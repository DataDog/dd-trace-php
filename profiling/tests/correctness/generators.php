<?php

/* The purpose of this test is to verify that stacks collected inside
 * generators which use `yield from` (delegation) correctly include the
 * delegating generator frame. PHP inserts a placeholder frame between the
 * delegate and the delegator which has a NULL `func`; without resolving it
 * via `zend_generator_check_placeholder_frame`, the delegator (`middle`)
 * would be missing from the stack.
 */

function leaf()
{
    $s = str_repeat("a", 1024 * 12_000);
    str_replace('a', 'b', $s);
    yield 1;
}

function middle()
{
    yield from leaf();
}

function main()
{
    $duration = $_ENV["EXECUTION_TIME"] ?? 10;
    $end = microtime(true) + $duration;
    while (microtime(true) < $end) {
        $start = microtime(true);
        foreach (middle() as $_) {
            // consume the generator
        }
        $elapsed = microtime(true) - $start;
        // sleep for the remainder to 100 ms so we end up doing 10 iterations per second
        $sleep = (0.1 - $elapsed);
        if ($sleep > 0.0) {
            usleep((int) ($sleep * 1_000_000));
        }
    }
}
main();
