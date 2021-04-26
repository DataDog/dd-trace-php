--TEST--
Long running manual flush
--ENV--
DD_TRACE_CLI_ENABLED=true
DD_TRACE_GENERATE_ROOT_SPAN=false
DD_TRACE_AUTO_FLUSH_ENABLED=false
--FILE--
<?php

require 'functions.inc';
require getenv('REQUEST_INIT_HOOK_PATH');

const ITERATIONS = 2;

for ($iteration = 0; $iteration < ITERATIONS; $iteration++) {
    $tracer = DDTrace\GlobalTracer::get();
    $scope = $tracer->startActiveSpan('manual_tracing');
    $span = $scope->getSpan();
    $span->setTag(DDTrace\Tag::SERVICE_NAME, 'manual_service');
    $span->setTag(DDTrace\Tag::SPAN_TYPE, 'custom');
    $span->setTag(DDTrace\Tag::RESOURCE_NAME, 'manual_resource');

    call_httpbin();

    $forkPid = pcntl_fork();

    if ($forkPid > 0) {
        // Main
        call_httpbin();
    } else if ($forkPid === 0) {
        // Child
        call_httpbin();
    } else {
        error_log('Error');
        exit(-1);
    }
    call_httpbin();
    $scope->close();
    $tracer->flush();
    $tracer->reset();
}

?>
--EXPECTF--
