<?php

namespace App;

use OpenTelemetry\SDK\Trace\TracerProvider;

class OpenTelemetryController
{
    public function render()
    {
        try {
            $tracerProvider = new TracerProvider();
            $tracer = $tracerProvider->getTracer('foo');
            $span = $tracer->spanBuilder('bar')
                ->startSpan()
            ;
            $span->end();
        } finally {
            \dd_trace_internal_fn("finalize_telemetry");
        }
    }
}
