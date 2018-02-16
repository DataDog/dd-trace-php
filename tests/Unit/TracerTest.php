<?php

namespace DDTrace\Tests\Unit;

use DDTrace\SpanContext;
use DDTrace\Tracer;
use DDTrace\Transport\Noop as NoopTransport;
use OpenTracing\NoopSpan;
use PHPUnit_Framework_TestCase;
use DDTrace\Time;

final class TracerTest extends PHPUnit_Framework_TestCase
{
    const OPERATION_NAME = 'test_span';
    const TAG_KEY = 'test_key';
    const TAG_VALUE = 'test_value';

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
}
