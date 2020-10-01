<?php

use DDTrace\Tracer;

error_log("Printed to the error log");
echo "This is the playground!\n";

if (class_exists('DDTrace\Tracer', $autoload = false)) {
    echo "Tracer version: " . Tracer::version() . "\n";
}
