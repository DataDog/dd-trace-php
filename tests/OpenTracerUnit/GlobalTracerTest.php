<?php

namespace DDTrace\Tests\OpenTracerUnit;

use DDTrace\GlobalTracer;
use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tracer;
use DDTrace\Transport\Noop;

final class GlobalTracerTest extends BaseTestCase
{
    public function testOpenTracingTracerCanBeSetUsingDDWrapper()
    {
        // Simulating DD tracer has already been set
        $tracer = new Tracer(new Noop());
        GlobalTracer::set($tracer);

        // As suggested in our docs: https://docs.datadoghq.com/tracing/opentracing/php/
        $otTracer = new \DDTrace\OpenTracer\Tracer(\DDTrace\GlobalTracer::get());
        \OpenTracing\GlobalTracer::set($otTracer);

        // Now accessing the OT tracer should return our wrapper
        $this->assertInstanceOf(
            '\DDTrace\OpenTracer\Tracer',
            \OpenTracing\GlobalTracer::get()
        );
    }
}
