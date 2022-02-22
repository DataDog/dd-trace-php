<?php

namespace DDTrace\Tests\OpenTracer1Unit;

use DDTrace\OpenTracer1\SpanContext;
use DDTrace\OpenTracer1\Tracer;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\SpanContext as DDSpanContext;
use DDTrace\Tag;
use DDTrace\Tests\DebugTransport;
use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Time;
use DDTrace\Transport\Noop as NoopTransport;
use OpenTracing\GlobalTracer;
use OpenTracing\Formats;

final class TracerTest extends BaseTestCase
{
    const OPERATION_NAME = 'test_span';
    const ANOTHER_OPERATION_NAME = 'test_span2';
    const TAG_KEY = 'test_key';
    const TAG_VALUE = 'test_value';
    const FORMAT = 'test_format';
    const ENVIRONMENT = 'my-env';
    const VERSION = '1.2.3';

    protected function ddSetUp()
    {
        \dd_trace_serialize_closed_spans();
        parent::ddSetUp();
    }

    public function testTracerNoConstructorArg()
    {
        $tracer = new Tracer();

        $span = $tracer->startSpan(self::OPERATION_NAME)->unwrapped();
        $this->assertNull($span->getTag(Tag::ENV));
        $this->assertNull($span->getTag(Tag::VERSION));
    }

    public function testTracerWithConstructorArg()
    {
        $tracer = new Tracer(\DDTrace\GlobalTracer::get());

        $span = $tracer->startSpan(self::OPERATION_NAME)->unwrapped();
        $this->assertNull($span->getTag(Tag::ENV));
        $this->assertNull($span->getTag(Tag::VERSION));
    }

    public function testCreateSpanWithDefaultTags()
    {
        $tracer = Tracer::make(new NoopTransport());

        $span = $tracer->startSpan(self::OPERATION_NAME)->unwrapped();
        $this->assertNull($span->getTag(Tag::ENV));
        $this->assertNull($span->getTag(Tag::VERSION));
    }

    public function testCreateSpanWithEnvAndVersionConfigured()
    {
        $this->putEnvAndReloadConfig(['DD_ENV=' . self::ENVIRONMENT, 'DD_VERSION=' . self::VERSION]);
        $tracer = Tracer::make(new NoopTransport());

        $span = $tracer->startSpan(self::OPERATION_NAME)->unwrapped();
        $this->assertSame(self::ENVIRONMENT, $span->getTag(Tag::ENV));
        $this->assertSame(self::VERSION, $span->getTag(Tag::VERSION));
    }

    public function testCreateSpanWithEnvAndVersionPrecedence()
    {
        $this->putEnvAndReloadConfig([
            'DD_ENV=' . self::ENVIRONMENT,
            'DD_VERSION=' . self::VERSION,
            'DD_TAGS=env:global-tag-env,version:4.5.6',
        ]);
        $tracer = Tracer::make(new NoopTransport());

        $span = $tracer->startSpan(self::OPERATION_NAME)->unwrapped();
        $this->assertSame(self::ENVIRONMENT, $span->getTag(Tag::ENV));
        $this->assertSame(self::VERSION, $span->getTag(Tag::VERSION));
    }

    public function testCreateSpanWithExpectedValues()
    {
        $tracer = Tracer::make(new NoopTransport());
        $rootSpan = $tracer->startSpan("rootSpan");
        $startTime = Time::now();
        $span = $tracer
            ->startSpan(self::OPERATION_NAME, [
                'tags' => [
                    self::TAG_KEY => self::TAG_VALUE
                ],
                'start_time' => $startTime,
                'child_of' => $rootSpan->unwrapped(),
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
        $this->assertEquals(getmypid(), $span->getTag(Tag::PID));
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

    public function testInjectThrowsUnsupportedFormatException()
    {
        $this->setExpectedException('\DDTrace\Exceptions\UnsupportedFormat');
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

    public function testExtractThrowsUnsupportedFormatException()
    {
        $this->setExpectedException('\DDTrace\Exceptions\UnsupportedFormat');
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

    public function testOTSpanContextAsParent()
    {
        GlobalTracer::set(Tracer::make());

        $tracer = GlobalTracer::get();

        $header = <<<JSON
{"x-datadog-trace-id":"2409624703365403319","x-datadog-parent-id":"2409624703365403319","x-datadog-sampling-priority":1}
JSON;
        $carrier = \json_decode($header, true);
        // Create a span from carrier
        $context = $tracer->extract(Formats\TEXT_MAP, $carrier);
        $B = $tracer->startActiveSpan('B', ['child_of' => $context]);

        $otcontext = $B->getSpan()->getContext();
        self::assertInstanceOf('DDTrace\OpenTracer1\SpanContext', $otcontext);
        self::assertEquals('2409624703365403319', $otcontext->unwrapped()->getTraceId());
    }

    public function testOTStartSpanOptions()
    {
        GlobalTracer::set(Tracer::make());
        $tracer = GlobalTracer::get();
        $tracer->startActiveSpan('dummy-root');

        $now = time();
        $scope = $tracer->startActiveSpan(
            self::OPERATION_NAME,
            \OpenTracing\StartSpanOptions::create([
                'tags' => [
                    \OpenTracing\Tags\SPAN_KIND => \OpenTracing\Tags\SPAN_KIND_MESSAGE_BUS_PRODUCER,
                    'message_id' => 'some id'
                ],
                'start_time' => $now,
            ])
        );
        self::assertInstanceOf('DDTrace\OpenTracer1\Scope', $scope);
        $scope = $scope->unwrapped();
        $span = $scope->getSpan();
        self::assertSame(\OpenTracing\Tags\SPAN_KIND_MESSAGE_BUS_PRODUCER, $span->getTag(\OpenTracing\Tags\SPAN_KIND));
        self::assertSame($now, $span->getStartTime());
    }

    public function testOnlyFinishedTracesAreBeingSent()
    {
        self::markTestIncomplete();
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
        self::markTestIncomplete();
        $tracer = Tracer::make(new DebugTransport());
        $tracer->startSpan(self::OPERATION_NAME);
        $this->assertSame(
            PrioritySampling::AUTO_KEEP,
            $tracer->unwrapped()->getPrioritySampling()
        );
    }

    public function testPrioritySamplingInheritedFromDistributedTracingContext()
    {
        self::markTestIncomplete();
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
        self::markTestIncomplete();
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
