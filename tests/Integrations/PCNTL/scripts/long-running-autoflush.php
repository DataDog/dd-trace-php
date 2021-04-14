<?php

require_once __DIR__ . '/_functions.php';

const ITERATIONS = 2;

\DDTrace\trace_function('long_running_entry_point', function ($span) {
    $span->type = 'custom';
    $span->service = 'pcntl-testing-service';
});

for ($iteration = 0; $iteration < ITERATIONS; $iteration++) {
    long_running_entry_point();
}

function long_running_entry_point()
{
    call_httpbin('get');

    $forkPid = pcntl_fork();

    if ($forkPid > 0) {
        // Main
        call_httpbin('headers');
    } else if ($forkPid === 0) {
        // Child
        call_httpbin('ip');
        exit(0);
    } else {
        error_log('Error');
        exit(-1);
    }
    call_httpbin('user-agent');
}
