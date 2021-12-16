<?php

namespace DDTrace\Tests\Unit\Encoders;

use DDTrace\Encoders\MessagePack;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\StartSpanOptions;
use DDTrace\Tests\DebugTransport;
use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tracer;
use DDTrace\GlobalTracer;

final class MessagePackTest extends BaseTestCase
{
    /**
     * @var Tracer
     */
    private $tracer;

    protected function ddSetUp()
    {
        parent::ddSetUp();
        $this->tracer = new Tracer(
            new DebugTransport(),
            null,
            [
                'service_name' => 'test_service',
                'resource' => 'test_resource',
            ]
        );
        GlobalTracer::set($this->tracer);

        self::putenv('DD_TRACE_GENERATE_ROOT_SPAN=0');
        dd_trace_internal_fn('ddtrace_reload_config');
    }

    protected function ddTearDown()
    {
        parent::ddTearDown();
        self::putenv('DD_AUTOFINISH_SPANS=');
        self::putenv('DD_TRACE_GENERATE_ROOT_SPAN');
        dd_trace_internal_fn('ddtrace_reload_config');
    }

    public function testEncodeNoPrioritySampling()
    {
        $span = $this->tracer->startRootSpan('test_name')->getSpan();
        $this->tracer->setPrioritySampling(\DD_TRACE_PRIORITY_SAMPLING_UNSET);
        $span->finish();

        $encoder = new MessagePack();
        $this->assertStringNotContains('_sampling_priority_v1', $encoder->encodeTraces($this->tracer));
    }

    public function testEncodeMetricsWhenPresent()
    {
        $span = $this->tracer->startRootSpan('test_name')->getSpan();
        $span->setMetric('_a', 0.1);
        $span->finish();

        $encoder = new MessagePack();
        $encoded = $encoder->encodeTraces($this->tracer);
        $this->assertStringContains("\xa7metrics", $encoded);
        $this->assertStringContains("\xa2_a\xcb\x3f\xb9\x99\x99\x99\x99\x99\x9a", $encoded);
    }

    public function testAlwaysContainsDefaultMetrics()
    {
        $span = $this->tracer->startRootSpan('test_name')->getSpan();
        $this->tracer->setPrioritySampling(\DD_TRACE_PRIORITY_SAMPLING_UNSET);
        $span->finish();

        $encoder = new MessagePack();
        $encoded = $encoder->encodeTraces($this->tracer);
        $this->assertStringContains('php.compilation.total_time_ms', $encoded);
    }
}
