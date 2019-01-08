<?php

namespace DDTrace\Tests\Unit;

use DDTrace\GlobalTracer;
use DDTrace\OpenTracer\Tracer as OpenTracer;
use DDTrace\Tracer;
use DDTrace\Transport\Noop;
use OpenTracing\GlobalTracer as OTGlobalTracer;
use PHPUnit\Framework;

final class GlobalTracerTest extends Framework\TestCase
{
    public function testDDTracerIsSetAsIs()
    {
        $tracer = new Tracer(new Noop());
        GlobalTracer::set($tracer);
        $this->assertSame($tracer, GlobalTracer::get());
    }

    public function testOpenTracingTracerIsOnlySetGlobally()
    {
        $tracer = new OpenTracer();
        GlobalTracer::set($tracer);
        $this->assertInstanceOf(
            'DDTrace\NoopTracer',
            GlobalTracer::get()
        );
        $this->assertInstanceOf(
            'DDTrace\OpenTracer\Tracer',
            OTGlobalTracer::get()
        );
    }
}
