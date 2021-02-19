<?php

use DDTrace\GlobalTracer;
use RandomizedTests\RandomExecutionPath;

$composerVendor = getenv('COMPOSER_VENDOR_DIR') ? : __DIR__ . '/../vendor';
require "$composerVendor/autoload.php";

$randomizer = new RandomExecutionPath($allowFatalAndUncaught = true);
set_error_handler([$randomizer, 'handleError']);
set_exception_handler([$randomizer, 'handleException']);
$output = $randomizer->randomPath();

// Legacy style manual tracing
$tracer = GlobalTracer::get();
$scope = $tracer->startActiveSpan('my_dummy_operation');
$scope->close();
