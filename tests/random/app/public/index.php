<?php

use DDTrace\GlobalTracer;

require __DIR__ . '/../vendor/autoload.php';

require __DIR__ . '/chaos.php';
$chaos = new Chaos($allowFatalAndUncaught = true);
set_error_handler([$chaos, 'handleError']);
set_exception_handler([$chaos, 'handleException']);
$output = $chaos->randomRequestPath();

$tracer = GlobalTracer::get();
$scope = $tracer->startActiveSpan('my_dummy_operation');
$scope->close();
