<?php

use DDTrace\GlobalTracer;
use RandomTests\RandomExecutionPath;

$composerVendor = getenv('COMPOSER_VENDOR_DIR') ? : __DIR__ . '/../vendor';
require "$composerVendor/autoload.php";

$chaos = new RandomExecutionPath($allowFatalAndUncaught = true);
set_error_handler([$chaos, 'handleError']);
set_exception_handler([$chaos, 'handleException']);
$output = $chaos->randomPath();

$tracer = GlobalTracer::get();
$scope = $tracer->startActiveSpan('my_dummy_operation');
$scope->close();
