<?php

namespace App;

// use DDTrace\OpenTracer1\Tracer;

class OpenTracingController
{
    public function render()
    {
        try {
            $otTracer = new \DDTrace\OpenTracer\Tracer(\DDTrace\GlobalTracer::get());
            $scope = $otTracer->startActiveSpan('web.request');
            $span = $scope->getSpan();
            $span->setTag('service.name', 'service_name');
            $span->setTag('resource.name', 'resource_name');
            $span->setTag('span.type', 'web');
            $span->setTag('http.method', $_SERVER['REQUEST_METHOD']);
            $span->finish();
        } finally {
            \dd_trace_internal_fn("finalize_telemetry");
        }
    }
}
