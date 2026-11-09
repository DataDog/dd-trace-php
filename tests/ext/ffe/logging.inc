<?php

function read_log_snapshot($file) {
    // The sidecar also appends other sessions' logs. Reading to EOF can keep
    // chasing new writes indefinitely under parallel Valgrind runs.
    // Refresh the size so repeated reads include this test's latest messages.
    clearstatcache(true, $file);
    $size = filesize($file);
    return $size > 0 ? file_get_contents($file, false, null, 0, $size) : '';
}
