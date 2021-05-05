<?php

require_once __DIR__ . '/_functions.php';

call_httpbin('get');

$forkPid = pcntl_fork();

if ($forkPid < 0) {
    // Error
    exit(-1);
} elseif ($forkPid == 0) {
    // Child
    call_httpbin('ip');
    $forkPid = pcntl_fork();
    if ($forkPid < 0) {
        // Error
        exit(-1);
    } else if ($forkPid == 0) {
        // Child
        call_httpbin('ip');
        $forkPid = pcntl_fork();
        if ($forkPid < 0) {
            // Error
            exit(-1);
        } elseif ($forkPid == 0) {
            // Child
            call_httpbin('ip');
        } else {
            pcntl_waitpid($forkPid, $childStatus);
        }
    } else {
        pcntl_waitpid($forkPid, $childStatus);
    }
} else {
    call_httpbin('headers');
    pcntl_waitpid($forkPid, $childStatus);
}

call_httpbin('user-agent');
