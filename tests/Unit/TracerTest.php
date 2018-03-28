<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Propagator;
use DDTrace\SpanContext;
use DDTrace\Tracer;
use DDTrace\Transport;
use DDTrace\Transport\Noop as NoopTransport;
use OpenTracing\Exceptions\UnsupportedFormat;
use OpenTracing\NoopSpan;
use PHPUnit_Framework_TestCase;
use DDTrace\Time;

final class TracerTest extends PHPUnit_Framework_TestCase
{
    const OPERATION_NAME = 'test_span';
    const ANOTHER_OPERATION_NAME = 'test_span2';
    const TAG_KEY = 'test_key';
    const TAG_VALUE = 'test_value';
    const FORMAT = 'test_format';

    public function testStartSpanAsNoop()
    {
        $tracer = Tracer::noop();
        $span = $tracer->startSpan(self::OPERATION_NAME);
        $this->assertInstanceOf(NoopSpan::class, $span);
    }

    public function testCreateSpanSuccessWithExpectedValues()
    {
        $tracer = new Tracer(new NoopTransport);
        $startTime = Time\now();
        $span = $tracer->startSpan(self::OPERATION_NAME, [
            'tags' => [
                self::TAG_KEY => self::TAG_VALUE
            ],
            'start_time' => $startTime,
        ]);

        $this->assertEquals(self::OPERATION_NAME, $span->getOperationName());
        $this->assertEquals(self::TAG_VALUE, $span->getTag(self::TAG_KEY));
        $this->assertEquals($startTime, $span->getStartTime());
    }

    public function testStartSpanAsChild()
    {
        $context = SpanContext::createAsRoot();
        $tracer = new Tracer(new NoopTransport);
        $span = $tracer->startSpan(self::OPERATION_NAME, [
            'child_of' => $context,
        ]);
        $this->assertEquals($context->getSpanId(), $span->getParentId());
    }

    public function testStartActiveSpan()
    {
        $tracer = new Tracer(new NoopTransport);
        $span = $tracer->startActiveSpan(self::OPERATION_NAME);
        $this->assertEquals($span, $tracer->getActiveSpan());
    }

    public function testStartActiveSpanAsChild()
    {
        $tracer = new Tracer(new NoopTransport);
        $parentSpan = $tracer->startActiveSpan(self::OPERATION_NAME);
        $childSpan = $tracer->startActiveSpan(self::ANOTHER_OPERATION_NAME);
        $this->assertEquals($childSpan, $tracer->getActiveSpan());
        $this->assertEquals($parentSpan->getSpanId(), $childSpan->getParentId());
    }

    public function testInjectThrowsUnsupportedFormatException()
    {
        $this->expectException(UnsupportedFormat::class);
        $context = SpanContext::createAsRoot();
        $carrier = [];

        $tracer = new Tracer(new NoopTransport);
        $tracer->inject($context, self::FORMAT, $carrier);
    }

    public function testInjectCallsTheRightInjector()
    {
        $context = SpanContext::createAsRoot();
        $carrier = [];

        $propagator = $this->prophesize(Propagator::class);
        $propagator->inject($context, $carrier)->shouldBeCalled();
        $tracer = new Tracer(new NoopTransport, [self::FORMAT => $propagator->reveal()]);
        $tracer->inject($context, self::FORMAT, $carrier);
    }

    public function testExtractThrowsUnsupportedFormatException()
    {
        $this->expectException(UnsupportedFormat::class);
        $carrier = [];
        $tracer = new Tracer(new NoopTransport);
        $tracer->extract(self::FORMAT, $carrier);
    }

    public function testExtractCallsTheRightExtractor()
    {
        $expectedContext = SpanContext::createAsRoot();
        $carrier = [];

        $propagator = $this->prophesize(Propagator::class);
        $propagator->extract($carrier)->shouldBeCalled()->willReturn($expectedContext);
        $tracer = new Tracer(new NoopTransport, [self::FORMAT => $propagator->reveal()]);
        $actualContext = $tracer->extract(self::FORMAT, $carrier);
        $this->assertEquals($expectedContext, $actualContext);
    }

    public function testOnlyFinishedTracesAreBeingSent()
    {
        $transport = $this->prophesize(Transport::class);
        $tracer = new Tracer($transport->reveal());
        $span = $tracer->startSpan(self::OPERATION_NAME);
        $tracer->startSpan(self::ANOTHER_OPERATION_NAME, [
            'child_of' => $span,
        ]);
        $span->finish();

        $span2 = $tracer->startSpan(self::OPERATION_NAME);
        $span3 = $tracer->startSpan(self::ANOTHER_OPERATION_NAME, [
            'child_of' => $span2,
        ]);
        $span2->finish();
        $span3->finish();

        $transport->send([
            [$span2, $span3],
        ])->shouldBeCalled();

        $tracer->flush();
    }
}
