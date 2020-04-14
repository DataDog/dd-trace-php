<?php

require_once '/dd-trace-php/bridge/dd_wrap_autoloader.php';

// Let's make sure that bootstrap happened
$tracer = DDTrace\GlobalTracer::get();

echo "Class: " . get_class($tracer) . "\n";
echo "Hi from init hook script!\n";
