<?php

require_once __DIR__ . '/_functions.php';

const ITERATIONS = 2;

for ($iteration = 0; $iteration < ITERATIONS; $iteration++) {
    $tracer = DDTrace\GlobalTracer::get();
    $scope = $tracer->startRootSpan('manual_tracing');
    $span = $scope->getSpan();
    $span->setTag(DDTrace\Tag::SERVICE_NAME, 'manual_service');
    $span->setTag(DDTrace\Tag::SPAN_TYPE, 'custom');
    $span->setTag(DDTrace\Tag::RESOURCE_NAME, 'manual_resource');

    call_httpbin('get');

    $forkPid = pcntl_fork();

    if ($forkPid > 0) {
        // Main
        call_httpbin('headers');
    } else if ($forkPid === 0) {
        // Child
        call_httpbin('ip');
    } else {
        error_log('Error');
        exit(-1);
    }
    call_httpbin('user-agent');
    $scope->close();
    $tracer->flush();
}
