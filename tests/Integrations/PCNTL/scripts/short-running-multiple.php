<?php

require_once __DIR__ . '/_functions.php';

const NUMBER_OF_CHILDREN = 5;

call_httpbin('get');

$currentIteration = 0;
while ($currentIteration !== NUMBER_OF_CHILDREN) {
    $forkPid = pcntl_fork();

    if ($forkPid > 0) {
        // Main
        call_httpbin('headers');
    } else if ($forkPid === 0) {
        // Child
        call_httpbin('ip');
        exit(0);
    } else {
        exit(-1);
    }

    $currentIteration++;
}

pcntl_wait($childStatus);

call_httpbin('user-agent');
