<?php

use OpenTelemetry\SDK\Trace\TracerProvider;

require __DIR__ . '/vendor/autoload.php';

try {
    $tracerProvider = new TracerProvider();
    $tracer = $tracerProvider->getTracer('foo');
    $span = $tracer->spanBuilder('barbar')
        ->startSpan()
    ;
    $span->end();
} finally {
    \dd_trace_internal_fn("finalize_telemetry");
}
