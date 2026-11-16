<?php

require_once __DIR__ . '/_functions.php';

const ITERATIONS = 2;

\DDTrace\trace_function('long_running_entry_point', function ($span) {
    $span->type = 'custom';
    $span->service = 'pcntl-testing-service';
});

for ($iteration = 0; $iteration < ITERATIONS; $iteration++) {
    long_running_entry_point($iteration);
}

function long_running_entry_point($iteration)
{
    call_httpbin('entry_point-'.$iteration);

    $forkPid = pcntl_fork();

    if ($forkPid > 0) {
        // Main
        call_httpbin('main_process-'.$iteration);
    } else if ($forkPid === 0) {
        // Child
        call_httpbin('child-'.$iteration);
        exit(0);
    } else {
        error_log('Error');
        exit(-1);
    }
    call_httpbin('end_entry_point-'.$iteration);
    pcntl_waitpid($forkPid, $childStatus);
}
