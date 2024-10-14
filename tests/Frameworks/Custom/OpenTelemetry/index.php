<?php

use OpenTelemetry\SDK\Trace\TracerProvider;

require __DIR__ . '/vendor/autoload.php';

$tracerProvider = new TracerProvider();
$tracer = $tracerProvider->getTracer('foo');
$span = $tracer->spanBuilder('barbar')
    ->startSpan()
;
$span->end();
