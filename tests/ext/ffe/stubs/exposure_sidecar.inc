<?php

function ffeAwaitExposureSidecar($routeChanged = false) {
    static $readyPid;
    if (!$routeChanged && $readyPid === getmypid()) return;
    $until = microtime(true) + (getenv('USE_ZEND_ALLOC') === '0' ? 120 : 30);
    while (!dd_trace_internal_fn('test_ffe_admission_ready')) {
        if (microtime(true) >= $until) throw new RuntimeException('exposure admission probe timed out');
        usleep(10000);
    }
    $readyPid = getmypid();
}
