<?php

use DDTrace\GlobalTracer;

$composerVendor = getenv('COMPOSER_VENDOR_DIR') ? : __DIR__ . '/../vendor';
require "$composerVendor/autoload.php";

require __DIR__ . '/chaos.php';
$chaos = new Chaos($allowFatalAndUncaught = true);
set_error_handler([$chaos, 'handleError']);
set_exception_handler([$chaos, 'handleException']);
$output = $chaos->randomRequestPath();

$tracer = GlobalTracer::get();
$scope = $tracer->startActiveSpan('my_dummy_operation');
$scope->close();
