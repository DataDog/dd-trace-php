<?php

function a() {
        $x = 0;
        $i = 0;
        // Keep each phase longer than the 10 ms sampling period. Very short
        // alternating phases can alias with the timer instead of sampling 1:2.
        while ($i < 10000000) {
                $x += $i;
                $i += 1;
        }
}

function b() {
        $x = 0;
        $i = 0;
        while ($i < 20000000) {
                $x += $i;
                $i += 1;
        }
}

function main() {
        $duration = $_ENV["EXECUTION_TIME"] ?? 10;
        $end = microtime(true) + ($duration / 2);
        while (microtime(true) < $end) {
                a();
                b();
        }
        sleep($duration / 2);
}
main();
