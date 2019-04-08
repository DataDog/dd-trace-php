<?php

namespace DDTrace\Tests\Unit\Encoders;

use DDTrace\Encoders\Json;
use DDTrace\Log\Logger;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\Tests\DebugTransport;
use DDTrace\Tests\Unit\BaseTestCase;
use DDTrace\Tracer;
use DDTrace\GlobalTracer;
use Prophecy\Argument;

final class JsonTest extends BaseTestCase
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
        $expectedPayload = <<<JSON
[[{"trace_id":%d,"span_id":%d,"name":"test_name","resource":"test_resource",
JSON
            .    <<<JSON
"service":"test_service","start":%d,"error":0,%s}]]
JSON;

        $this->tracer->startSpan('test_name');

        $logger = $this->prophesize('DDTrace\Log\LoggerInterface');
        $logger->debug(Argument::any())->shouldNotBeCalled();

        $jsonEncoder = new Json($logger->reveal());
        $encodedTrace = $jsonEncoder->encodeTraces($this->tracer);
        $this->assertStringMatchesFormat($expectedPayload, $encodedTrace);
    }

    public function testEncodeIgnoreSpanWhenEncodingFails()
    {
        if (self::matchesPhpVersion('5.4')) {
            $this->markTestSkipped(
                'json_encode in php < 5.6 does not fail because of malformed string. It sets null on specific key'
            );
            return;
        }

        $expectedPayload = '[[]]';

        $span = $this->tracer->startSpan('test_name');
        // this will generate a malformed UTF-8 string
        $span->setTag('invalid', hex2bin('37f2bef0ab085308'));

        $logger = $this->prophesize('DDTrace\Log\LoggerInterface');
        $logger
            ->isLevelActive('debug')
            ->shouldBeCalled();
        $logger
            ->debug(
                'Failed to json-encode trace: Malformed UTF-8 characters, possibly incorrectly encoded',
                []
            )
            ->shouldBeCalled();
        Logger::set($logger->reveal());

        $jsonEncoder = new Json();
        $encodedTrace = $jsonEncoder->encodeTraces($this->tracer);
        $this->assertEquals($expectedPayload, $encodedTrace);
    }

    public function testEncodeNoPrioritySampling()
    {
        $this->tracer->startSpan('test_name');
        $this->tracer->setPrioritySampling(null);

        $jsonEncoder = new Json();
        $this->assertNotContains('_sampling_priority_v1', $jsonEncoder->encodeTraces($this->tracer));
    }

    public function testEncodeWithPrioritySampling()
    {
        $this->tracer->startSpan('test_name');
        $this->tracer->setPrioritySampling(PrioritySampling::USER_KEEP);

        $jsonEncoder = new Json();
        $this->assertContains('"_sampling_priority_v1":2', $jsonEncoder->encodeTraces($this->tracer));
    }

    public function testEncodeMetricsWhenPresent()
    {
        $span = $this->tracer->startSpan('test_name');
        $span->setMetric('_a', 0.1);

        $jsonEncoder = new Json();
        $encoded = $jsonEncoder->encodeTraces($this->tracer);
        $this->assertContains('"_a":0.1', $encoded);
    }

    public function testDoesNotEncodeMetricsWhenNotPresent()
    {
        $this->tracer->startSpan('test_name');
        $this->tracer->setPrioritySampling(null);

        $jsonEncoder = new Json();
        $encoded = $jsonEncoder->encodeTraces($this->tracer);
        $this->assertNotContains('"metrics"', $encoded);
    }
}
