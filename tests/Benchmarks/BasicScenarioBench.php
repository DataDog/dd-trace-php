<?php

declare(strict_types=1);

namespace DDTrace\Benchmarks;

use OpenTelemetry\SDK\Trace\TracerProvider;

class BasicScenarioBench
{
    public $otelTracer;

    /**
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(3.0)
     */
    public function benchDatadogAPI()
    {
        $span = \DDTrace\start_trace_span();
        $span->name = 'bench.basic_scenario';
        $span->meta['foo'] = 'bar';
        $span->metrics['bar'] = 1;
        \DDTrace\close_span();
    }

    /**
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(3.0)
     * @BeforeMethods("setUpOpenTelemetry")
     */
    public function benchOpenTelemetryAPI()
    {
        $span = $this->otelTracer->spanBuilder('bench.basic_scenario')->setParent(false)->startSpan();
        $span->setAttribute('foo', 'bar');
        $span->setAttribute('bar', 1);
        $span->end();
    }

    public function setUpOpenTelemetry()
    {
        $this->otelTracer = (new TracerProvider())->getTracer('benchmarks');
    }
}
