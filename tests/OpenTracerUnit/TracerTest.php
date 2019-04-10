<?php

namespace DDTrace\Tests\OpenTracerUnit;

use DDTrace\Configuration;
use DDTrace\OpenTracer\SpanContext;
use DDTrace\OpenTracer\Tracer;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\SpanContext as DDSpanContext;
use DDTrace\Tag;
use DDTrace\Tests\DebugTransport;
use DDTrace\Time;
use DDTrace\Transport\Noop as NoopTransport;
use PHPUnit\Framework\TestCase;

final class TracerTest extends TestCase
{
    const OPERATION_NAME = 'test_span';
    const ANOTHER_OPERATION_NAME = 'test_span2';
    const TAG_KEY = 'test_key';
    const TAG_VALUE = 'test_value';
    const FORMAT = 'test_format';

    public function testCreateSpanWithExpectedValues()
    {
        $tracer = Tracer::make(new NoopTransport());
        $startTime = Time::now();
        $span = $tracer
            ->startSpan(self::OPERATION_NAME, [
                'tags' => [
                    self::TAG_KEY => self::TAG_VALUE
                ],
                'start_time' => $startTime,
            ])
            ->unwrapped();

        $this->assertSame(self::OPERATION_NAME, $span->getOperationName());
        $this->assertSame(self::TAG_VALUE, $span->getTag(self::TAG_KEY));
        $this->assertSame($startTime, $span->getStartTime());
    }

    public function testStartSpanAsChild()
    {
        $context = DDSpanContext::createAsRoot();
        $tracer = Tracer::make(new NoopTransport());
        $span = $tracer
            ->startSpan(self::OPERATION_NAME, [
                'child_of' => $context,
            ])
            ->unwrapped();
        $this->assertSame($context->getSpanId(), $span->getParentId());
        $this->assertNull($span->getTag(Tag::PID));
    }

    public function testStartSpanAsRootWithPid()
    {
        $tracer = Tracer::make(new NoopTransport());
        $span = $tracer->startSpan(self::OPERATION_NAME)->unwrapped();
        $this->assertSame((string) getmypid(), $span->getTag(Tag::PID));
    }

    public function testStartActiveSpan()
    {
        $tracer = Tracer::make(new NoopTransport());
        $scope = $tracer->startActiveSpan(self::OPERATION_NAME);
        $this->assertSame(
            $scope->unwrapped(),
            $tracer->getScopeManager()->getActive()->unwrapped()
        );
    }

    public function testStartActiveSpanAsChild()
    {
        $tracer = Tracer::make(new NoopTransport());
        $parentScope = $tracer->startActiveSpan(self::OPERATION_NAME);
        $parentSpan = $parentScope->getSpan();
        $parentSpan->setTag(Tag::SERVICE_NAME, 'parent_service');
        $childScope = $tracer->startActiveSpan(self::ANOTHER_OPERATION_NAME);
        $this->assertSame(
            $childScope->unwrapped(),
            $tracer->getScopeManager()->getActive()->unwrapped()
        );
        $this->assertSame(
            $parentScope->getSpan()->unwrapped()->getSpanId(),
            $childScope->getSpan()->unwrapped()->getParentId()
        );
        $this->assertSame(
            $parentScope->getSpan()->unwrapped()->getService(),
            $childScope->getSpan()->unwrapped()->getService()
        );
    }

    /**
     * @expectedException \DDTrace\Exceptions\UnsupportedFormat
     */
    public function testInjectThrowsUnsupportedFormatException()
    {
        $carrier = [];

        $tracer = Tracer::make(new NoopTransport());
        $tracer->inject(
            new SpanContext(DDSpanContext::createAsRoot()),
            self::FORMAT,
            $carrier
        );
    }

    public function testInjectCallsTheRightInjector()
    {
        $context = new SpanContext(DDSpanContext::createAsRoot());
        $carrier = [];

        $propagator = $this->prophesize('DDTrace\Propagator');
        $propagator->inject($context->unwrapped(), $carrier)->shouldBeCalled();
        $tracer = Tracer::make(new NoopTransport(), [self::FORMAT => $propagator->reveal()]);
        $tracer->inject($context, self::FORMAT, $carrier);
    }

    /**
     * @expectedException \DDTrace\Exceptions\UnsupportedFormat
     */
    public function testExtractThrowsUnsupportedFormatException()
    {
        $carrier = [];
        $tracer = Tracer::make(new NoopTransport());
        $tracer->extract(self::FORMAT, $carrier);
    }

    public function testExtractCallsTheRightExtractor()
    {
        $expectedContext = DDSpanContext::createAsRoot();
        $carrier = [];

        $propagator = $this->prophesize('DDTrace\Propagator');
        $propagator->extract($carrier)->shouldBeCalled()->willReturn($expectedContext);
        $tracer = Tracer::make(new NoopTransport(), [self::FORMAT => $propagator->reveal()]);
        $actualContext = $tracer->extract(self::FORMAT, $carrier);
        $this->assertSame($expectedContext, $actualContext->unwrapped());
    }

    public function testOnlyFinishedTracesAreBeingSent()
    {
        $transport = $this->prophesize('DDTrace\Transport');
        $tracer = Tracer::make($transport->reveal());
        $span = $tracer->startSpan(self::OPERATION_NAME);
        $tracer->startSpan(self::ANOTHER_OPERATION_NAME, [
            'child_of' => $span->unwrapped(),
        ]);
        $span->finish();

        $span2 = $tracer->startSpan(self::OPERATION_NAME);
        $span3 = $tracer->startSpan(self::ANOTHER_OPERATION_NAME, [
            'child_of' => $span2->unwrapped(),
        ]);
        $span2->finish();
        $span3->finish();

        $transport->send([
            [$span2->unwrapped(), $span3->unwrapped()],
        ])->shouldBeCalled();

        $tracer->flush();
    }

    public function testPrioritySamplingIsAssigned()
    {
        $tracer = Tracer::make(new DebugTransport());
        $tracer->startSpan(self::OPERATION_NAME);
        $this->assertSame(
            PrioritySampling::AUTO_KEEP,
            $tracer->unwrapped()->getPrioritySampling()
        );
    }

    public function testPrioritySamplingInheritedFromDistributedTracingContext()
    {
        $distributedTracingContext = new DDSpanContext('', '', '', [], true);
        $distributedTracingContext->setPropagatedPrioritySampling(PrioritySampling::USER_REJECT);
        $tracer = Tracer::make(new DebugTransport());
        $tracer->startSpan(self::OPERATION_NAME, [
            'child_of' => $distributedTracingContext,
        ]);
        $this->assertSame(
            PrioritySampling::USER_REJECT,
            $tracer->unwrapped()->getPrioritySampling()
        );
    }

    public function testUnfinishedSpansAreNotSentOnFlush()
    {
        $transport = new DebugTransport();
        $tracer = Tracer::make($transport);
        $tracer->startActiveSpan('root');
        $tracer->startActiveSpan('child');

        $tracer->flush();

        $this->assertEmpty($transport->getTraces());
    }

    public function testUnfinishedSpansCanBeFinishedOnFlush()
    {
        Configuration::replace(\Mockery::mock('\DDTrace\Configuration', [
            'isAutofinishSpansEnabled' => true,
            'isPrioritySamplingEnabled' => false,
            'isDebugModeEnabled' => false,
        ]));

        $transport = new DebugTransport();
        $tracer = Tracer::make($transport);
        $tracer->startActiveSpan('root');
        $tracer->startActiveSpan('child');

        $tracer->flush();
        $sent = $transport->getTraces();
        $this->assertSame('root', $sent[0][0]->getOperationName());
        $this->assertSame('child', $sent[0][1]->getOperationName());
    }
}
