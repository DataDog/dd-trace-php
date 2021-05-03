--TEST--
Long running autoflush
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=false
DD_TRACE_AUTO_FLUSH_ENABLED=true
--FILE--
<?php

require 'functions.inc';
require __DIR__ . '/../includes/fake_tracer.inc';
require __DIR__ . '/../includes/fake_global_tracer.inc';

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
    call_httpbin();

    $forkPid = pcntl_fork();

    if ($forkPid > 0) {
        // Main
        call_httpbin();
    } else if ($forkPid === 0) {
        // Child
        call_httpbin();
        exit(0);
    } else {
        error_log('Error');
        exit(-1);
    }
    call_httpbin();
}

?>
--EXPECTF--
Flushing tracer...
long_running_entry_point (pcntl-testing-service, long_running_entry_point, custom)
Tracer reset
Flushing tracer...
long_running_entry_point (pcntl-testing-service, long_running_entry_point, custom)
Tracer reset
Flushing tracer...
long_running_entry_point (pcntl-testing-service, long_running_entry_point, custom)
Tracer reset
Flushing tracer...
long_running_entry_point (pcntl-testing-service, long_running_entry_point, custom)
Tracer reset
