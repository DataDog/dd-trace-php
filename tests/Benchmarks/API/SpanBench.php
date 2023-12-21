<?php

declare(strict_types=1);

namespace Benchmarks\API;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Trace\TracerProvider;

class SpanBench
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

        for ($i = 0; $i < 6; $i++) {
            $childSpan = \DDTrace\start_span();
            $childSpan->name = 'bench.basic_scenario.child';
            $childSpan->meta['foo'] = 'bar';
            $childSpan->metrics['bar'] = 1;
            \DDTrace\close_span();
        }

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

        for ($i = 0; $i < 6; $i++) {
            $childSpan = $this->otelTracer->spanBuilder('bench.basic_scenario.child')->startSpan();
            $childSpan->setAttribute('foo', 'bar');
            $childSpan->setAttribute('bar', 1);
            $childSpan->end();
        }

        $span->end();
    }

    /**
     * @Revs(1000)
     * @Iterations(10)
     * @OutputTimeUnit("microseconds")
     * @RetryThreshold(3.0)
     * @BeforeMethods("setUpOpenTelemetry")
     */
    public function benchOpenTelemetryInteroperability()
    {
        $span = \DDTrace\start_trace_span();
        $span->name = 'bench.basic_scenario';
        $span->meta['foo'] = 'bar';
        $span->metrics['bar'] = 1;

        for ($i = 0; $i < 6; $i++) {
            $childSpan = \DDTrace\start_span();
            $childSpan->name = 'bench.basic_scenario.child';
            $childSpan->meta['foo'] = 'bar';
            $childSpan->metrics['bar'] = 1;
        }

        Span::getCurrent(); // Triggers OpenTelemetry span creation

        for ($i = 0; $i < 6; $i++) {
            \DDTrace\close_span();
        }

        \DDTrace\close_span();
    }

    public function setUpOpenTelemetry()
    {
        $this->otelTracer = (new TracerProvider())->getTracer('benchmarks');
    }
}
