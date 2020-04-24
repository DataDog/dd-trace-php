<?php

require __DIR__ . '/vendor/autoload.php';

// Even with composer version out of sync, we should still be able to have a noop
// manual instrumentation

$tracer = \DDTrace\GlobalTracer::get();
error_log(get_class($tracer));
$scope = $tracer->startActiveSpan('customer');
$scope->close();

echo DDTrace\Tracer::VERSION . ' ' . get_class($tracer);
