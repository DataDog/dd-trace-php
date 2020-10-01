<?php

use DDTrace\Tracer;

error_log("Printed to the error log");
echo "This is the playground!\n";

echo "Tracer version: " . Tracer::version() . "\n";
