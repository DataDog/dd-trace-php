<?php

function ffeAwaitExposureSidecar($routeChanged = false) {
    static $readyPid;
    if (!$routeChanged && $readyPid === getmypid()) return;
    // A completed request puts the worker's connection setup ahead of the measured admissions.
    $stats = dd_trace_internal_fn('stats_sidecar');
    if (!is_string($stats) || !is_array(json_decode($stats, true))) {
        throw new RuntimeException('expected a ready exposure sidecar');
    }
    $until = microtime(true) + 15;
    while (!dd_trace_internal_fn('test_ffe_admission_ready')) {
        if (microtime(true) >= $until) throw new RuntimeException('exposure admission probe timed out');
        usleep(10000);
    }
    $readyPid = getmypid();
}
