<?php

namespace DDTrace\Tests\Integration\Common;

use DDTrace\Span;
use DDTrace\Tests\DebugTransport;
use DDTrace\Tracer;
use OpenTracing\GlobalTracer;


trait TracerTestTrait
{
    /**
     * @param $fn
     * @return Span[][]
     */
    public function isolateTracer($fn)
    {
        $transport = new DebugTransport();
        $tracer = new Tracer($transport);
        GlobalTracer::set($tracer);

        $fn($tracer);
        return $this->flushAndGetTraces($transport);
    }

    /**
     * @param $fn
     * @return Span[][]
     */
    public function simulateWebRequestTracer($fn)
    {
        $tracer = GlobalTracer::get();
        $transport = new DebugTransport();

        // Replacing the transport in the current tracer
        $tracerReflection = new \ReflectionObject($tracer);
        $tracerTransport = $tracerReflection->getProperty('transport');
        $tracerTransport->setAccessible(true);
        $tracerTransport->setValue($tracer, $transport);

        $fn($tracer);

        // We have to close the active span for web frameworks, this is what is typically done in
        // `register_shutdown_function`.
        // We need yet to find a strategy, though, to make sure that the `register_shutdown_function` is actually there
        // and that do not magically disappear. Here we are faking things.
        $tracer->getActiveSpan()->finish();

        return $this->flushAndGetTraces($transport);
    }

    /**
     * @param DebugTransport $transport
     * @return Span[][]
     */
    protected function flushAndGetTraces($transport)
    {
        /** @var Tracer $tracer */
        $tracer = GlobalTracer::get();
        /** @var DebugTransport $transport */
        $tracer->flush();
        return $transport->getTraces();
    }

    /**
     * @param $name string
     * @param $fn
     * @return Span[][]
     */
    public function inTestScope($name, $fn)
    {
        return $this->isolateTracer(function ($tracer) use ($fn, $name) {
            $scope = $tracer->startActiveSpan($name);
            $fn($tracer);
            $scope->close();
        });
    }
}
