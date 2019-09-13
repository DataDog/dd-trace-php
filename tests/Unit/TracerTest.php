<?php

namespace DDTrace\Tests\Unit;

use DDTrace\Configuration;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\SpanContext;
use DDTrace\Tag;
use DDTrace\Tests\DebugTransport;
use DDTrace\Time;
use DDTrace\Tracer;
use DDTrace\Transport\Noop as NoopTransport;

final class TracerTest extends BaseTestCase
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
        $this->assertInstanceOf('DDTrace\NoopSpan', $span);
    }

    public function testCreateSpanSuccessWithExpectedValues()
    {
        $tracer = new Tracer(new NoopTransport());
        $startTime = Time::now();
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
        $tracer = new Tracer(new NoopTransport());
        $span = $tracer->startSpan(self::OPERATION_NAME, [
            'child_of' => $context,
        ]);
        $this->assertEquals($context->getSpanId(), $span->getParentId());
        $this->assertNull($span->getTag(Tag::PID));
    }

    public function testStartSpanAsRootWithPid()
    {
        $tracer = new Tracer(new NoopTransport());
        $span = $tracer->startSpan(self::OPERATION_NAME);
        $this->assertEquals(getmypid(), $span->getTag(Tag::PID));
    }

    public function testStartActiveSpan()
    {
        $tracer = new Tracer(new NoopTransport());
        $scope = $tracer->startActiveSpan(self::OPERATION_NAME);
        $this->assertEquals($scope, $tracer->getScopeManager()->getActive());
    }

    public function testStartActiveSpanAsChild()
    {
        $tracer = new Tracer(new NoopTransport());
        $parentScope = $tracer->startActiveSpan(self::OPERATION_NAME);
        $parentSpan = $parentScope->getSpan();
        $parentSpan->setTag(Tag::SERVICE_NAME, 'parent_service');
        $childScope = $tracer->startActiveSpan(self::ANOTHER_OPERATION_NAME);
        $this->assertEquals($childScope, $tracer->getScopeManager()->getActive());
        $this->assertEquals($parentScope->getSpan()->getSpanId(), $childScope->getSpan()->getParentId());
        $this->assertEquals($parentScope->getSpan()->getService(), $childScope->getSpan()->getService());
    }

    /**
     * @expectedException \DDTrace\Exceptions\UnsupportedFormat
     */
    public function testInjectThrowsUnsupportedFormatException()
    {
        $context = SpanContext::createAsRoot();
        $carrier = [];

        $tracer = new Tracer(new NoopTransport());
        $tracer->inject($context, self::FORMAT, $carrier);
    }

    public function testInjectCallsTheRightInjector()
    {
        $context = SpanContext::createAsRoot();
        $carrier = [];

        $propagator = $this->prophesize('DDTrace\Propagator');
        $propagator->inject($context, $carrier)->shouldBeCalled();
        $tracer = new Tracer(new NoopTransport(), [self::FORMAT => $propagator->reveal()]);
        $tracer->inject($context, self::FORMAT, $carrier);
    }

    /**
     * @expectedException \DDTrace\Exceptions\UnsupportedFormat
     */
    public function testExtractThrowsUnsupportedFormatException()
    {
        $carrier = [];
        $tracer = new Tracer(new NoopTransport());
        $tracer->extract(self::FORMAT, $carrier);
    }

    public function testExtractCallsTheRightExtractor()
    {
        $expectedContext = SpanContext::createAsRoot();
        $carrier = [];

        $propagator = $this->prophesize('DDTrace\Propagator');
        $propagator->extract($carrier)->shouldBeCalled()->willReturn($expectedContext);
        $tracer = new Tracer(new NoopTransport(), [self::FORMAT => $propagator->reveal()]);
        $actualContext = $tracer->extract(self::FORMAT, $carrier);
        $this->assertEquals($expectedContext, $actualContext);
    }

    public function testOnlyFinishedTracesAreBeingSent()
    {
        $transport = $this->prophesize('DDTrace\Transport');
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

        $transport->send($tracer)->shouldBeCalled();

        $tracer->flush();
    }

    public function testPrioritySamplingIsAssigned()
    {
        $tracer = new Tracer(new DebugTransport());
        $tracer->startSpan(self::OPERATION_NAME);
        $this->assertSame(PrioritySampling::AUTO_KEEP, $tracer->getPrioritySampling());
    }

    public function testPrioritySamplingInheritedFromDistributedTracingContext()
    {
        $distributedTracingContext = new SpanContext('', '', '', [], true);
        $distributedTracingContext->setPropagatedPrioritySampling(PrioritySampling::USER_REJECT);
        $tracer = new Tracer(new DebugTransport());
        $tracer->startSpan(self::OPERATION_NAME, [
            'child_of' => $distributedTracingContext,
        ]);
        $this->assertSame(PrioritySampling::USER_REJECT, $tracer->getPrioritySampling());
    }

    public function testUnfinishedSpansAreNotSentOnFlush()
    {
        $transport = new DebugTransport();
        $tracer = new Tracer($transport);
        $tracer->startActiveSpan('root');
        $tracer->startActiveSpan('child');

        $tracer->flush();

        $this->assertEmpty($transport->getTraces());
    }

    public function testUnfinishedSpansCanBeFinishedOnFlush()
    {
        Configuration::replace(\Mockery::mock(Configuration::get(), [
            'isAutofinishSpansEnabled' => true,
            'isPrioritySamplingEnabled' => false,
            'isDebugModeEnabled' => false,
            'getGlobalTags' => [],
        ]));

        $transport = new DebugTransport();
        $tracer = new Tracer($transport);
        $tracer->startActiveSpan('root');
        $tracer->startActiveSpan('child');

        $tracer->flush();
        $sent = $transport->getTraces();
        $this->assertSame('root', $sent[0][0]['name']);
        $this->assertSame('child', $sent[0][1]['name']);
    }

    public function testSpanStartedAtRootCanBeAccessedLater()
    {
        $tracer = new Tracer(new NoopTransport());
        $scope = $tracer->startRootSpan(self::OPERATION_NAME);
        $this->assertSame($scope, $tracer->getRootScope());
    }

    public function testFlushDoesntAddHostnameToRootSpanByDefault()
    {
        $tracer = new Tracer(new NoopTransport());
        $scope = $tracer->startRootSpan(self::OPERATION_NAME);
        $this->assertNull($tracer->getRootScope()->getSpan()->getTag(Tag::HOSTNAME));

        $tracer->flush();

        $this->assertNull($tracer->getRootScope()->getSpan()->getTag(Tag::HOSTNAME));
    }

    public function testFlushAddsHostnameToRootSpanWhenEnabled()
    {
        Configuration::replace(\Mockery::mock(Configuration::get(), [
            'isHostnameReportingEnabled' => true
        ]));

        $tracer = new Tracer(new NoopTransport());
        $scope = $tracer->startRootSpan(self::OPERATION_NAME);
        $this->assertNull($tracer->getRootScope()->getSpan()->getTag(Tag::HOSTNAME));

        $tracer->flush();

        $this->assertEquals(gethostname(), $tracer->getRootScope()->getSpan()->getTag(Tag::HOSTNAME));
    }

    public function testIfNoRootScopeExistsItWillBeNull()
    {
        $tracer = new Tracer(new NoopTransport());
        $this->assertNull($tracer->getRootScope());
    }

    public function testHonorGlobalTags()
    {
        Configuration::replace(\Mockery::mock(Configuration::get(), [
            'isAutofinishSpansEnabled' => true,
            'isPrioritySamplingEnabled' => false,
            'isDebugModeEnabled' => false,
            'getGlobalTags' => [
                'key1' => 'value1',
                'key2' => 'value2',
            ],
        ]));

        $transport = new DebugTransport();
        $tracer = new Tracer($transport);
        $span = $tracer->startSpan('custom');

        $this->assertSame('value1', $span->getAllTags()['key1']);
        $this->assertSame('value2', $span->getAllTags()['key2']);
    }

    public function testInternalAndUserlandSpansAreMergedIntoSameTraceOnSerialization()
    {
        if (PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('Sandbox API not available on < PHP 5.6');
            return;
        }
        dd_trace_function('array_sum', function () {
            // Do nothing
        });
        $tracer = new Tracer(new DebugTransport());
        $span = $tracer->startSpan('foo');
        array_sum([1, 2]);
        $span->finish();

        $this->assertSame(2, dd_trace_closed_spans_count());
        $traces = $tracer->getTracesAsArray();
        $this->assertCount(1, $traces);
        $this->assertCount(2, $traces[0]);
    }
}
