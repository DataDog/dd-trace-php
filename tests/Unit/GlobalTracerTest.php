<?php

namespace DDTrace\Tests\Unit;

use DDTrace\GlobalTracer;
use DDTrace\Tracer;
use DDTrace\Transport\Noop;
use PHPUnit\Framework;

final class GlobalTracerTest extends Framework\TestCase
{
    public function testDDTracerIsSetAsIs()
    {
        $tracer = new Tracer(new Noop());
        GlobalTracer::set($tracer);
        $this->assertSame($tracer, GlobalTracer::get());
    }

    public function testOpenTracingTracerIsSetWithWrapper()
    {
        $tracer = new Tracer(new Noop());
        GlobalTracer::set($tracer);
        $this->assertInstanceOf(
            '\DDTrace\OpenTracer\Tracer',
            \OpenTracing\GlobalTracer::get()
        );
    }
}
