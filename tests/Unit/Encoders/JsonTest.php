<?php

namespace DDTrace\Tests\Unit\Encoders;

use DDTrace\Encoders\Json;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\Span;
use DDTrace\SpanContext;
use PHPUnit\Framework;
use Prophecy\Argument;
use Psr\Log\LoggerInterface;

final class JsonTest extends Framework\TestCase
{
    public function testEncodeTracesSuccess()
    {
        $expectedPayload = <<<JSON
[[{"trace_id":1589331357723252209,"span_id":1589331357723252210,"name":"test_name","resource":"test_resource",
JSON
            .    <<<JSON
"service":"test_service","start":1518038421211969000,"error":0}]]
JSON;

        $context = new SpanContext('160e7072ff7bd5f1', '160e7072ff7bd5f2');
        $span = new Span(
            'test_name',
            $context,
            'test_service',
            'test_resource',
            1518038421211969
        );

        $logger = $this->prophesize(LoggerInterface::class);
        $logger->debug(Argument::any())->shouldNotBeCalled();

        $jsonEncoder = new Json($logger->reveal());
        $encodedTrace = $jsonEncoder->encodeTraces([[$span]]);
        $this->assertJsonStringEqualsJsonString($expectedPayload, $encodedTrace);
    }

    public function testEncodeIgnoreSpanWhenEncodingFails()
    {
        $expectedPayload = '[[]]';

        $context = new SpanContext('160e7072ff7bd5f1', '160e7072ff7bd5f2');
        $span = new Span(
            'test_name',
            $context,
            'test_service',
            'test_resource',
            1518038421211969
        );
        // this will generate a malformed UTF-8 string
        $span->setTag('invalid', hex2bin('37f2bef0ab085308'));

        $logger = $this->prophesize(LoggerInterface::class);
        $logger
            ->debug(
                'Failed to json-encode span: Malformed UTF-8 characters, possibly incorrectly encoded'
            )
            ->shouldBeCalled();

        $jsonEncoder = new Json($logger->reveal());
        $encodedTrace = $jsonEncoder->encodeTraces([[$span, $span]]);
        $this->assertEquals($expectedPayload, $encodedTrace);
    }

    public function testEncodeNoPrioritySampling()
    {
        $context = new SpanContext('tid', 'sid');
        $span = new Span(
            'test_name',
            $context,
            'test_service',
            'test_resource',
            1518038421211969
        );

        $jsonEncoder = new Json();
        $this->assertNotContains('_sampling_priority_v1', $jsonEncoder->encodeTraces([[$span]]));
    }

    public function testEncodeWithPrioritySampling()
    {
        $context = new SpanContext('tid', 'sid');
        $span = new Span(
            'test_name',
            $context,
            'test_service',
            'test_resource',
            1518038421211969
        );
        $span->setPrioritySampling(PrioritySampling::USER_KEEP);

        $jsonEncoder = new Json();
        $this->assertContains('"_sampling_priority_v1":2', $jsonEncoder->encodeTraces([[$span]]));
    }
}
