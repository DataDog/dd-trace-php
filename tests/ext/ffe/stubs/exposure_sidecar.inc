<?php

function ffeAwaitExposureSidecar() {
    static $readyPid;
    if ($readyPid === getmypid()) return;
    // A completed request puts the worker's connection setup ahead of the measured admissions.
    $stats = dd_trace_internal_fn('stats_sidecar');
    if (!is_string($stats) || !is_array(json_decode($stats, true))) {
        throw new RuntimeException('expected a ready exposure sidecar');
    }
    $readyPid = getmypid();
}
