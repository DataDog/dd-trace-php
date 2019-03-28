<?php

namespace DDTrace\Tests\Unit\Encoders;

use DDTrace\Encoders\MessagePack;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\Tests\DebugTransport;
use DDTrace\Tests\Unit\BaseTestCase;
use DDTrace\Tracer;
use DDTrace\GlobalTracer;

final class MessagePackTest extends BaseTestCase
{
    /**
     * @var Tracer
     */
    private $tracer;

    protected function setUp()
    {
        parent::setUp();
        putenv('DD_AUTOFINISH_SPANS=true');
        $this->tracer = new Tracer(
            new DebugTransport(),
            null,
            [
                'service_name' => 'test_service',
                'resource' => 'test_resource',
            ]
        );
        GlobalTracer::set($this->tracer);
    }

    protected function tearDown()
    {
        parent::tearDown();
        putenv('DD_AUTOFINISH_SPANS=');
    }

    public function testEncodeTracesSuccess()
    {
        $expectedPayload = <<<MPACK
\x91\x91%a\xa8trace_id%aspan_id%aname%atest_name%aresource%atest_resource%aservice%atest_service%astart%aerror%a
MPACK;

        $this->tracer->startSpan('test_name');

        $encoder = new MessagePack();
        $encodedTrace = $encoder->encodeTraces($this->tracer);
        $this->assertStringMatchesFormat($expectedPayload, $encodedTrace);
    }

    public function testEncodeNoPrioritySampling()
    {
        $this->tracer->startSpan('test_name');
        $this->tracer->setPrioritySampling(null);

        $encoder = new MessagePack();
        $this->assertNotContains('_sampling_priority_v1', $encoder->encodeTraces($this->tracer));
    }

    public function testEncodeWithPrioritySampling()
    {
        $this->tracer->startSpan('test_name');
        $this->tracer->setPrioritySampling(PrioritySampling::USER_KEEP);

        $encoder = new MessagePack();
        $this->assertContains("\xb5_sampling_priority_v1\x02", $encoder->encodeTraces($this->tracer));
    }

    public function testEncodeMetricsWhenPresent()
    {
        $span = $this->tracer->startSpan('test_name');
        $span->setMetric('_a', 0.1);

        $encoder = new MessagePack();
        $encoded = $encoder->encodeTraces($this->tracer);
        $this->assertContains("\xa7metrics", $encoded);
        $this->assertContains("\xa2_a\xcb\x3f\xb9\x99\x99\x99\x99\x99\x9a", $encoded);
    }

    public function testDoesNotEncodeMetricsWhenNotPresent()
    {
        $this->tracer->startSpan('test_name');
        $this->tracer->setPrioritySampling(null);

        $encoder = new MessagePack();
        $encoded = $encoder->encodeTraces($this->tracer);
        $this->assertNotContains("\xa7metrics", $encoded);
    }
}
