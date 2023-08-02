<?php

function a() {
        $x = 0;
        $i = 0;
        while ($i < 100000) {
                $x += $i;
                $i += 1;
        }
}

function b() {
        $x = 0;
        $i = 0;
        while ($i < 200000) {
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
