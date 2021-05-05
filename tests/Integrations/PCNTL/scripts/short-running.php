<?php

require_once __DIR__ . '/_functions.php';

$forkPid = pcntl_fork();

call_httpbin('get');

if ($forkPid > 0) {
    // Main
    call_httpbin('headers');
    pcntl_wait($childStatus);
    call_httpbin('user-agent');
} else {
    // Child
    call_httpbin('ip');
}
