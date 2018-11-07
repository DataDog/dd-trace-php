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
    public function withTracer($fn)
    {
        $transport = new DebugTransport();
        $tracer = new Tracer($transport);
        GlobalTracer::set($tracer);

        $fn($tracer);
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
        return $this->withTracer(function ($tracer) use ($fn, $name) {
            $scope = $tracer->startActiveSpan($name);
            $fn($tracer);
            $scope->close();
        });
    }
}
