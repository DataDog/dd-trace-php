<?php

namespace DDTrace;

use OpenTracing\GlobalTracer;
use DDTrace\Tests\DebugTransport;

trait DebugTracer
{
    /** @var Tracer */
    protected $debugTracer;

    public function initTracer()
    {
        $this->debugTracer = new Tracer(new DebugTransport);
        GlobalTracer::set($this->debugTracer);
    }

    public function flushTracer()
    {
        $this->debugTracer->flush();
    }
}
