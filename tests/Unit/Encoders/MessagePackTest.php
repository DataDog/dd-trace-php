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
        $this->tracer->setPrioritySampling(null);
        $span->finish();

        $encoder = new MessagePack();
        $this->assertStringNotContains('_sampling_priority_v1', $encoder->encodeTraces($this->tracer));
    }

    public function testEncodeWithPrioritySampling()
    {
        if (PHP_VERSION_ID >= 70000) {
            $this->assertTrue(true); // no warning
            return; // priority sampling is no longer set upon encoding, other tests are covering this
        }

        $span = $this->tracer->startRootSpan('test_name')->getSpan();
        $this->tracer->setPrioritySampling(PrioritySampling::USER_KEEP);
        $span->finish();

        $encoder = new MessagePack();
        $this->assertStringContains("\xb5_sampling_priority_v1\x02", $encoder->encodeTraces($this->tracer));
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
        $this->tracer->setPrioritySampling(null);
        $span->finish();

        $encoder = new MessagePack();
        $encoded = $encoder->encodeTraces($this->tracer);
        $this->assertStringContains('php.compilation.total_time_ms', $encoded);
    }
}
