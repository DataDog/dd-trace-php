<?php

namespace DDTrace\Tests\Unit;

use DDTrace\GlobalTracer;
use DDTrace\Tracer;
use DDTrace\Transport\Noop;
use DDTrace\Tests\Common\BaseTestCase;

final class GlobalTracerTest extends BaseTestCase
{
    private static function startTracingTest() {
        $tracer = GlobalTracer::get();
        GlobalTracer::set($tracer);
        $tracer->startRootSpan('foo');

        GlobalTracer::get()->getRootScope()->close();
        GlobalTracer::get()->flush();
    }

    public function testMemoryResetAfterFlush()
    {
        self::putenv('DD_TRACE_GENERATE_ROOT_SPAN=0');
        dd_trace_internal_fn('ddtrace_reload_config');

        try {
            self::startTracingTest();
            $mem1 = memory_get_usage();
            self::startTracingTest();
            $mem2 = memory_get_usage();
        } catch (\Throwable $e) {
            self::putenv('DD_TRACE_GENERATE_ROOT_SPAN=');
            throw $e;
        } finally {
            $this->assertEquals($mem1, $mem2);
            self::putenv('DD_TRACE_GENERATE_ROOT_SPAN=');
        }
    }

    public function testDDTracerIsSetAsIs()
    {
        $tracer = new Tracer(new Noop());
        GlobalTracer::set($tracer);
        $this->assertSame($tracer, GlobalTracer::get());
    }
}
