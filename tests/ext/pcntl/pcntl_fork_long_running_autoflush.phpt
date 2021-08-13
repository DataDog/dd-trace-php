--TEST--
Long running autoflush
--SKIPIF--
<?php
include __DIR__ . '/../includes/skipif_no_dev_env.inc';
if (!extension_loaded('pcntl')) die('skip: pcntl extension required');
if (!extension_loaded('curl')) die('skip: curl extension required');
?>
<?php if (PHP_VERSION_ID < 70000) die('skip: Test requires internal spans'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=false
DD_TRACE_AUTO_FLUSH_ENABLED=true
DD_TRACE_DEBUG=1
--FILE--
<?php

require 'functions.inc';

const ITERATIONS = 2;

\DDTrace\trace_function('long_running_entry_point', function ($span) {
    $span->type = 'custom';
    $span->service = 'pcntl-testing-service';
});

for ($iteration = 0; $iteration < ITERATIONS; $iteration++) {
    long_running_entry_point();
    usleep(200000);
}

function long_running_entry_point()
{
    call_httpbin();

    $forkPid = pcntl_fork();

    ob_start();

    if ($forkPid > 0) {
        // Main
        call_httpbin();
    } else if ($forkPid === 0) {
        // Child
        usleep(100000);
        call_httpbin();
    } else {
        error_log('Error');
        exit(-1);
    }
    call_httpbin();
}

?>
--EXPECTF--
Successfully triggered flush with trace of size 1
Traces are dropped by PID %d because global 'drop_all_spans' is set.
Successfully triggered flush with trace of size 1
Successfully triggered flush with trace of size 1
Traces are dropped by PID %d because global 'drop_all_spans' is set.
Successfully triggered flush with trace of size 1
No finished traces to be sent to the agent
No finished traces to be sent to the agent
No finished traces to be sent to the agent
No finished traces to be sent to the agent
