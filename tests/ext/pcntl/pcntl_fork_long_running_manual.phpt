--TEST--
Long running manual flush
--SKIPIF--
<?php if (!extension_loaded('pcntl')) die('skip: pcntl extension required'); ?>
--ENV--
DD_TRACE_CLI_ENABLED=true
DD_TRACE_GENERATE_ROOT_SPAN=false
DD_TRACE_AUTO_FLUSH_ENABLED=false
--FILE--
<?php

require 'functions.inc';

require __DIR__ . '/../includes/fake_scope.inc';
require __DIR__ . '/../includes/fake_span.inc';
require __DIR__ . '/../includes/fake_tracer.inc';
require __DIR__ . '/../includes/fake_global_tracer.inc';

const ITERATIONS = 2;

for ($iteration = 0; $iteration < ITERATIONS; $iteration++) {
    $tracer = DDTrace\GlobalTracer::get();
    $scope = $tracer->startActiveSpan('manual_tracing');
    $span = $scope->getSpan();
    $span->setTag('service.name', 'manual_service');
    $span->setTag('span.type', 'custom');
    $span->setTag('resource.name', 'manual_resource');

    call_httpbin();

    $forkPid = pcntl_fork();

    ob_start();

    if ($forkPid > 0) {
        // Main
        call_httpbin();
    } else if ($forkPid === 0) {
        // Child
        call_httpbin();
    } else {
        echo 'Error' . PHP_EOL;
        exit(-1);
    }
    call_httpbin();
    $scope->close();
    $tracer->flush();
    $tracer->reset();

    $output = ob_get_contents();
    ob_end_clean();
    $lines = explode(PHP_EOL, $output);
    if (in_array("Flushing tracer...", $lines) && in_array("Tracer reset", $lines)) {
        echo "OK" . PHP_EOL;
    }
}

?>
--EXPECTF--
OK
OK
OK
OK
OK
OK
