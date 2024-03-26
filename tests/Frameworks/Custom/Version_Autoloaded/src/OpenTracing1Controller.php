<?php

namespace App;

class OpenTracing1Controller
{
    public function render()
    {
        try {
            $otTracer = new \DDTrace\OpenTracer1\Tracer(\DDTrace\GlobalTracer::get());
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
