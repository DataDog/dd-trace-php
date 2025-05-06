<?php

namespace DDTrace\Tests\OpenTelemetry\Integration\API;

use DDTrace\Sampling\PrioritySampling;
use DDTrace\Tag;
use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\TracerTestTrait;
use OpenTelemetry\API\Trace\NonRecordingSpan;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TraceState;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SamplingResult;
use OpenTelemetry\SDK\Trace\Span;
use OpenTelemetry\SDK\Trace\SpanBuilder;
use OpenTelemetry\SDK\Trace\TracerProvider;
use function DDTrace\active_span;

final class TracerTest extends BaseTestCase
{
    use TracerTestTrait, SpanAssertionTrait;

    public function ddSetUp(): void
    {
        \dd_trace_serialize_closed_spans();
        self::putEnv("DD_TRACE_GENERATE_ROOT_SPAN=0");
        parent::ddSetUp();
    }

    public function ddTearDown()
    {
        Context::setStorage(new ContextStorage()); // Reset OpenTelemetry context
        self::putEnv("DD_TRACE_GENERATE_ROOT_SPAN=");
        parent::ddTearDown();
        \dd_trace_serialize_closed_spans();
    }

    public static function getTracer()
    {
        $uniqueKey = substr(md5(uniqid()), 0, 10);
        $tracer = (new TracerProvider([], new AlwaysOnSampler()))->getTracer("OpenTelemetry.TracerTest$uniqueKey");
        return $tracer;
    }

    public function testOtelSetSpanStatusError()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $errorSpan = $tracer->spanBuilder('error_span')->startSpan();
            $errorSpanScope = $errorSpan->activate();
            $errorSpan->setStatus(StatusCode::STATUS_ERROR, "error_desc");
            $errorSpan->setStatus(StatusCode::STATUS_UNSET, "unset_desc");
            $errorSpanScope->detach();
            $errorSpan->end();
        });

        $this->assertSame('error_desc', $traces[0][0]['meta']['error.message']);
    }

    public function testUnorderedOtelSpanActivation()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $span1 = $tracer->spanBuilder('test.span1')->startSpan();
            $span2 = $tracer->spanBuilder('test.span2')->startSpan();
            $span3 = $tracer->spanBuilder('test.span3')->startSpan();

            $scope1 = $span1->activate();
            $scope3 = $span3->activate();
            $scope2 = $span2->activate();

            $scope2->detach();
            $scope3->detach();
            $scope1->detach();

            $span1->end();
            $span2->end();
            $span3->end();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('internal', 'test.span1', null, (PHP_VERSION_ID < 80100) ? 'datadog/dd-trace-tests' : 'unknown_service:php'),
            SpanAssertion::exists('internal', 'test.span2', null, (PHP_VERSION_ID < 80100) ? 'datadog/dd-trace-tests' : 'unknown_service:php'),
            SpanAssertion::exists('internal', 'test.span3', null, (PHP_VERSION_ID < 80100) ? 'datadog/dd-trace-tests' : 'unknown_service:php'),
        ]);
    }

    public function testManuallyCreatedSpanWithNoCustomTags()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            /** @var Span $span */
            $span = $tracer->spanBuilder('test.span')->startSpan();
            $span->end();
        });

        $span = $traces[0][0];
        $this->assertNotEmpty($span['trace_id']);
        $this->assertSame($span['trace_id'], $span['span_id']);
        $this->assertNotEquals(0, $span['duration']);
        $this->assertSame('internal', $span['name']);
        $this->assertSame('test.span', $span['resource']);
        $this->assertEquals(PrioritySampling::AUTO_KEEP, $span['metrics']["_sampling_priority_v1"]);
    }

    public function testManuallyCreatedSpanWithCustomTags()
    {
        $traces = $this->isolateTracer(function () {
           $tracerProvider = new TracerProvider();
           $tracer = $tracerProvider->getTracer('OpenTelemetry.TracerTest', 'dev', 'http://url', ['foo' => 'bar']);
           /** @var Span $span */
            $span = $tracer->spanBuilder('test.span')
                ->setAttribute('foo', 'bar')
                ->setAttribute('bar', 'baz')
                ->startSpan();
            $span->end();
        });

        $span = $traces[0][0];
        $this->assertNotEmpty($span['trace_id']);
        $this->assertSame($span['trace_id'], $span['span_id']);
        $this->assertNotEquals(0, $span['duration']);
        $this->assertSame('internal', $span['name']);
        $this->assertSame('test.span', $span['resource']);
        $this->assertEquals(PrioritySampling::AUTO_KEEP, $span['metrics']["_sampling_priority_v1"]);
        $this->assertSame('bar', $span['meta']['foo']);
        $this->assertSame('baz', $span['meta']['bar']);
    }

    public function testManuallyCreatedSpanWithNestedAttributes()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            /** @var Span $span */
            $span = $tracer->spanBuilder('test.span')
                ->setAttribute('foo', 'bar')
                ->setAttribute('bar', 'baz')
                ->setAttribute('nested', ['foo' => 'bar', 'bar' => 'baz', 'alone'])
                ->startSpan();
            $span->end();
        });

        $span = $traces[0][0];
        $this->assertNotEmpty($span['trace_id']);
        $this->assertSame($span['trace_id'], $span['span_id']);
        $this->assertNotEquals(0, $span['duration']);
        $this->assertSame('internal', $span['name']);
        $this->assertSame('test.span', $span['resource']);
        $this->assertEquals(PrioritySampling::AUTO_KEEP, $span['metrics']["_sampling_priority_v1"]);
        $this->assertSame('bar', $span['meta']['foo']);
        $this->assertSame('baz', $span['meta']['bar']);
        $this->assertSame('bar', $span['meta']['nested.foo']);
        $this->assertSame('baz', $span['meta']['nested.bar']);
        $this->assertSame('alone', $span['meta']['nested.0']);
    }

    public function testManuallyCreatedNestedSpansBasic()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();

            $parent = $tracer->spanBuilder("parent")->startSpan();
            $scope = $parent->activate();
            try {
                $child = $tracer->spanBuilder("child")->startSpan();
                $child->end();
            } finally {
                $parent->end();
                $scope->detach();
            }
        });

        $spans = $traces[0];
        list($parentSpan, $childSpan) = $spans;
        $this->assertNotEmpty($parentSpan['trace_id']);
        $this->assertSame($parentSpan['trace_id'], $parentSpan['span_id']);
        $this->assertSame($parentSpan['trace_id'], $childSpan['trace_id']);
        $this->assertSame($parentSpan['span_id'], $childSpan['trace_id']);
        $this->assertNotSame($parentSpan['span_id'], $childSpan['span_id']);
        $this->assertNotSame($childSpan['trace_id'], $childSpan['span_id']);
    }

    public function testCreateSpanWithParentContext()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $parent = $tracer->spanBuilder("parent")->startSpan();
            $child = $tracer->spanBuilder("child")
                ->setParent(Context::getCurrent()->withContextValue($parent))
                ->startSpan();

            $child->end();
            $parent->end();
        });

        $spans = $traces[0];
        list($parentSpan, $childSpan) = $spans;
        $this->assertNotEmpty($parentSpan['trace_id']);
        $this->assertSame($parentSpan['trace_id'], $parentSpan['span_id']);
        $this->assertSame($parentSpan['trace_id'], $childSpan['trace_id']);
        $this->assertSame($parentSpan['span_id'], $childSpan['trace_id']);
        $this->assertNotSame($parentSpan['span_id'], $childSpan['span_id']);
        $this->assertNotSame($childSpan['trace_id'], $childSpan['span_id']);
    }

    public function testCreateSpanInvalidParent()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $child = $tracer->spanBuilder("child")
                ->setParent(Context::getCurrent()->withContextValue(Span::getInvalid()))
                ->startSpan();
            $child->end();
        });

        $span = $traces[0][0];
        $this->assertNotEmpty($span['trace_id']);
        $this->assertSame($span['trace_id'], $span['span_id']);
        $this->assertArrayNotHasKey('parent_id', $span);
        $this->assertEquals(PrioritySampling::AUTO_KEEP, $span['metrics']["_sampling_priority_v1"]);
    }

    public function testCreateANewTraceInTheSameProcess()
    {
        // credits: https://github.com/open-telemetry/opentelemetry-php/blob/main/examples/traces/features/creating_a_new_trace_in_the_same_process.php
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            // This creates a span and sets it as the current parent (and root) span
            $rootSpan = $tracer->spanBuilder('foo')->startSpan();
            $rootScope = $rootSpan->activate();

            // This creates (and closes) a child span
            $childSpan = $tracer->spanBuilder('bar')->startSpan();
            $childSpan->end();

            // This closes the root/parent span and detaches its scope/context
            $rootSpan->end();
            $rootScope->detach();

            // This creates a new span as a parent/root, however regardless of calling "activate" on it, it will have a new TraceId
            $span = $tracer->spanBuilder('baz')->startSpan();
            $scope = $span->activate();

            $span->end();
            $scope->detach();
        });

        $spans = $traces[0];
        list($span, $rootSpan, $childSpan) = $spans;

        $this->assertNotEmpty($rootSpan['trace_id']);
        $this->assertSame($rootSpan['trace_id'], $rootSpan['span_id']);
        $this->assertSame($rootSpan['trace_id'], $childSpan['trace_id']);
        $this->assertSame($rootSpan['span_id'], $childSpan['parent_id']);

        $this->assertNotEmpty($span['trace_id']);
        $this->assertSame($span['trace_id'], $span['span_id']);
        $this->assertNotSame($rootSpan['trace_id'], $span['trace_id']);
    }

    public function testSpanAttributes()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $span = $tracer->spanBuilder('test.span')
                ->setAttribute("string", "a")
                ->setAttribute("null-string", null)
                ->setAttributes([
                    "empty_string" => "",
                    "number" => 1,
                    "boolean" => true,
                    "string-array" => ["a", "b", "c"],
                    "boolean-array" => [true, false],
                    "float-array" => [1.1, 2.2, 3.3],
                    "empty-array" => [],
                    "mixed-array" => [1, "a", true, 1.1, null, []], # Dropped because non-primitive, non-homogeneous array
                ])
                ->startSpan();
            $span->end();
        });

        $meta = $traces[0][0]['meta'];
        $metrics = $traces[0][0]['metrics'];
        $this->assertSame("a", $meta['string']);
        $this->assertArrayNotHasKey("null-string", $meta);
        $this->assertSame("", $meta['empty_string']);
        $this->assertEquals(1, $metrics['number']);
        $this->assertSame("true", $meta['boolean']);
        $this->assertSame("a", $meta['string-array.0']);
        $this->assertSame("b", $meta['string-array.1']);
        $this->assertSame("c", $meta['string-array.2']);
        $this->assertSame("true", $meta['boolean-array.0']);
        $this->assertSame("false", $meta['boolean-array.1']);
        $this->assertEquals("1.1", $metrics['float-array.0']);
        $this->assertEquals("2.2", $metrics['float-array.1']);
        $this->assertEquals("3.3", $metrics['float-array.2']);
        $this->assertSame("", $meta['empty-array']);
    }

    /**
     * @dataProvider providerSpanKind
     */
    public function testSpanKind($otelSpanKind, $tagSpanKind)
    {
        $traces = $this->isolateTracer(function () use ($otelSpanKind) {
            $tracer = self::getTracer();
            $span = $tracer->spanBuilder('test.span')
                ->setSpanKind($otelSpanKind)
                ->startSpan();
            $span->end();
        });

        $span = $traces[0][0];
        $this->assertSame($tagSpanKind, $span['meta']['span.kind']);
    }

    public function providerSpanKind()
    {
        return [
            [SpanKind::KIND_CLIENT, Tag::SPAN_KIND_VALUE_CLIENT],
            [SpanKind::KIND_SERVER, Tag::SPAN_KIND_VALUE_SERVER],
            [SpanKind::KIND_PRODUCER, Tag::SPAN_KIND_VALUE_PRODUCER],
            [SpanKind::KIND_CONSUMER, Tag::SPAN_KIND_VALUE_CONSUMER],
            [SpanKind::KIND_INTERNAL, Tag::SPAN_KIND_VALUE_INTERNAL],
        ];
    }

    public function providerAnalyticsEvent()
    {
        return [
            ["true", 1],
            ["TRUE", 1],
            ["True", 1],
            ["false", 0],
            ["False", 0],
            ["FALSE", 0],
            ["something-else", null],
            [True, 1],
            [False, 0],
            ['t', 1],
            ['T', 1],
            ['f', 0],
            ['F', 0],
            ['1', 1],
            ['0', 0],
            ['fAlse', null],
            ['trUe', null]
        ];
    }

    /**
     * @dataProvider providerAnalyticsEvent
     */
    public function testReservedAttributesOverridesAnalyticsEvent($analyticsEventValue, $expectedMetricValue)
    {
        $traces = $this->isolateTracer(function () use ($analyticsEventValue) {
            $tracer = self::getTracer();
            $span = $tracer->spanBuilder('operation')
                ->setSpanKind(SpanKind::KIND_SERVER)
                ->startSpan();
            $span->setAttribute('analytics.event', $analyticsEventValue);
            $span->end();
        });

        $span = $traces[0][0];
        if ($expectedMetricValue !== null) {
            $actualMetricValue = $span['metrics']['_dd1.sr.eausr'];
            $this->assertEquals($expectedMetricValue, $actualMetricValue);
        } else {
            $this->assertArrayNotHasKey('_dd1.sr.eausr', $span['metrics']);
        }
    }

    public function testSpanErrorStatus()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $span = $tracer->spanBuilder('test.span')->startSpan();
            $span->setStatus(StatusCode::STATUS_ERROR, "error message");
            $span->end();
        });

        $span = $traces[0][0];
        $this->assertSame('internal', $span['name']);
        $this->assertSame('test.span', $span['resource']);
        $this->assertSame('error message', $span['meta']['error.message']);
        $this->assertEquals(1, $span['error']);
    }

    public function testSpanStatusTransition()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $span = $tracer->spanBuilder('test.span')->startSpan();
            $span->setStatus(StatusCode::STATUS_UNSET);
            $this->assertArrayNotHasKey(Tag::ERROR_MSG, active_span()->meta); // Initial state
            $span->setStatus(StatusCode::STATUS_ERROR, "error message");
            $this->assertSame("error message", active_span()->meta[Tag::ERROR_MSG]); // Error state
            $span->setStatus(StatusCode::STATUS_UNSET);
            $this->assertSame("error message", active_span()->meta[Tag::ERROR_MSG]); // Unchanged state
            $span->setStatus(StatusCode::STATUS_OK);
            $this->assertArrayNotHasKey(Tag::ERROR_MSG, active_span()->meta); // OK state
            $span->end();
        });

        $span = $traces[0][0];
        $this->assertArrayNotHasKey("error", $span);
        $this->assertArrayNotHasKey("error.message", $span['meta']);
        $this->assertSame("internal", $span['name']);
        $this->assertSame("test.span", $span['resource']);
    }

    public function testRecordException()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $span = $tracer->spanBuilder('test.span')->startSpan();
            $span->recordException(new \RuntimeException("exception message"));
            $span->end();
        });

        $span = $traces[0][0];
        $this->assertSame('internal', $span['name']);
        $this->assertSame('test.span', $span['resource']);
        $this->assertNotEmpty($span['meta'][Tag::ERROR_STACK]);
    }

    public function testSpanNameUpdate()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $span = $tracer->spanBuilder('test.span')->startSpan();
            $span->updateName('new.name');
            $span->end();
        });

        $span = $traces[0][0];
        $this->assertSame('new.name', $span['resource']);
    }

    public function testSpanUpdateAfterEnd()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $span = $tracer->spanBuilder('test.span')->startSpan();
            $span->setStatus(StatusCode::STATUS_ERROR, "error message");
            $span->setAttribute('foo', 'bar');
            $span->end();
            $span->setAttribute('foo', 'baz');
            $span->setStatus(StatusCode::STATUS_OK);
            $span->updateName('new.name');
            $span->setAttributes([
                'foo' => 'quz',
                'bar' => 'baz'
            ]);
        });

        $span = $traces[0][0];
        $this->assertSame('internal', $span['name']);
        $this->assertSame('test.span', $span['resource']);
        $this->assertSame('error message', $span['meta']['error.message']);
        $this->assertEquals(1, $span['error']);
        $this->assertSame('bar', $span['meta']['foo']);
    }

    public function testConcurrentSpans()
    {
        // credits: https://github.com/open-telemetry/opentelemetry-php/blob/main/examples/traces/features/concurrent_spans.php
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();

            $rootSpan = $tracer->spanBuilder('root')->startSpan();
            $scope = $rootSpan->activate();

            // Because the root span is active, each of the following spans will be parented to the root span
            try {
                $spans = [];
                for ($i = 1; $i <= 3; $i++) {
                    $s = Span::fromContext(Context::getCurrent());
                    $spans[] = $tracer->spanBuilder('http-' . $i)
                        //@see https://github.com/open-telemetry/opentelemetry-collector/blob/main/model/semconv/v1.6.1/trace.go#L834
                        ->setAttribute('http.method', 'GET')
                        ->setAttribute('http.url', 'example.com/' . $i)
                        ->setAttribute('http.status_code', "200")
                        ->setAttribute('http.response_content_length', "1024")
                        ->startSpan();
                }
                /** @psalm-suppress ArgumentTypeCoercion */
                foreach ($spans as $span) {
                    usleep((int) (0.3 * 1e6));
                    $span->end();
                }
            } finally {
                $scope->detach();
                $rootSpan->end();
            }
        });

        $spans = $traces[0];
        $rootSpan = $spans[0];
        $httpSpans = [$spans[3], $spans[2], $spans[1]];
        $this->assertCount(4, $spans);

        $traceId = $rootSpan['trace_id'];
        $this->assertNotEmpty($traceId);

        $this->assertSame($traceId, $rootSpan['span_id']);
        for ($i = 1; $i <= 3; $i++) {
            $httpSpan = $httpSpans[$i - 1];
            $this->assertSame($traceId, $httpSpan['trace_id']);
            $this->assertSame($rootSpan['span_id'], $httpSpan['parent_id']);

            $this->assertSame("http-$i", $httpSpan['resource']);
            $this->assertSame("GET", $httpSpan['meta']['http.method']);
            $this->assertSame("example.com/$i", $httpSpan['meta']['http.url']);
            $this->assertSame('200', $httpSpan['meta']['http.status_code']);
            $this->assertSame('1024', $httpSpan['meta']['http.response_content_length']);
        }
    }

    public function testGetSpanContextWithMultipleTraceStates()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $span = $tracer->spanBuilder('test.span')->startSpan();
            $span->setAttributes([
                '_dd.p.congo' => 't61rcWkgMzE',
                '_dd.p.some_val' => 'tehehe'
            ]);
            $this->assertRegularExpression('/^dd=p:[0-9a-f]{16};t.congo:t61rcWkgMzE;t.some_val:tehehe;t.dm:-0$/', (string)$span->getContext()->getTraceState());
            $span->end();
            $this->assertRegularExpression('/^dd=p:[0-9a-f]{16};t.congo:t61rcWkgMzE;t.some_val:tehehe;t.dm:-0$/', (string)$span->getContext()->getTraceState());
        });

        $span = $traces[0][0];
        $this->assertSame('t61rcWkgMzE', $span['meta']['_dd.p.congo']);
        $this->assertSame('tehehe', $span['meta']['_dd.p.some_val']);
    }

    /**
     * @dataProvider providerRemoteParent
     */
    public function testGetSpanContextWithRemoteParent(int $traceFlags, ?TraceState $traceState)
    {
        // 128-bit trace id = low-64 trace_id (as decimal) + high-64 _dd.p.tid (as hex) * 2^64
        $low = "11803532876627986230";
        $high = "4bf92f3577b34da6";
        $decSpanId = "67667974448284343";

        $traces = $this->isolateTracer(function () use ($traceFlags, $traceState, $low, $decSpanId) {
            $remoteContext =  SpanContext::createFromRemoteParent(
                '4bf92f3577b34da6a3ce929d0e0e4736',
                '00f067aa0ba902b7',
                $traceFlags,
                $traceState
            );
            $remoteSpan = new NonRecordingSpan($remoteContext);

            $tracer = self::getTracer();
            $spanBuilder = $tracer->spanBuilder('test.span');
            $this->assertInstanceOf(SpanBuilder::class, $spanBuilder);
            $child = $spanBuilder
                ->setParent(Context::getCurrent()->withContextValue($remoteSpan))
                ->startSpan();
            $scope = $child->activate();
            $this->assertTrue($child->isRecording());
            $this->assertInstanceOf(Span::class, $child);
            $scope->detach();
            $child->end();

            $childContext = $child->getContext();
            $this->assertSame($remoteContext->getTraceId(), $childContext->getTraceId());
            $this->assertSame($remoteContext->getSpanId(), $child->getParentContext()->getSpanId());
            $this->assertFalse($childContext->isRemote()); // "When creating children from remote spans, their IsRemote flag MUST be set to false."
            $this->assertEquals(1, $childContext->getTraceFlags()); // RECORD_AND_SAMPLED ==> 01 (AlwaysOn sampler)
            $this->assertSame("dd=p:" . $child->getContext()->getSpanID() . ";t.dm:-0" . ($traceState ? ",$traceState" : ""), (string)$childContext->getTraceState());
        });

        $span = $traces[0][0];
        $this->assertSame($low, $span['trace_id']);
        $this->assertSame($high, $span['meta']['_dd.p.tid']);
        $this->assertSame($decSpanId, $span['parent_id']);
    }

    public function providerRemoteParent()
    {
        return [
            [TraceFlags::SAMPLED, null],
            [TraceFlags::SAMPLED, new TraceState("rojo=00f067aa0ba902b7,congo=t61rcWkgMzE")],
            [TraceFlags::DEFAULT, null],
            [TraceFlags::DEFAULT, new TraceState("rojo=00f067aa0ba902b7,congo=t61rcWkgMzE")],
        ];
    }

    public function testMultipleTraceState()
    {
        $traces = $this->isolateTracer(function () {
            $root = new class() implements SamplerInterface {
                public function shouldSample(
                    ContextInterface $parentContext,
                    string $traceId,
                    string $spanName,
                    int $spanKind,
                    AttributesInterface $attributes,
                    array $links
                ): SamplingResult {
                    return new SamplingResult(
                        SamplingResult::RECORD_AND_SAMPLE,
                        [],
                        new TraceState("root=yes,rojo=00f067aa0ba902b7,congo=t61rcWkgMzE")
                    );
                }

                public function getDescription(): string
                {
                    return "Custom Sampler";
                }
            };

            $localParentSampler = new class() implements SamplerInterface {
                public function shouldSample(
                    ContextInterface $parentContext,
                    string $traceId,
                    string $spanName,
                    int $spanKind,
                    AttributesInterface $attributes,
                    array $links
                ): SamplingResult {
                    return new SamplingResult(
                        SamplingResult::RECORD_AND_SAMPLE,
                        [],
                        new TraceState("localparent=yes,rojo=00f067aa0ba902b7,congo=t61rcWkgMzE")
                    );
                }

                public function getDescription(): string
                {
                    return "Custom Sampler";
                }
            };

            // Create a new tracer with a parent based sampling
            $tracer = (new TracerProvider([], new ParentBased(
                $root,
                null,
                null,
                $localParentSampler,
            )))->getTracer('OpenTelemetry.TracerTest');
            $parent = $tracer->spanBuilder("parent")->startSpan(); // root sampler will be used
            $scope = $parent->activate();
            $this->assertRegularExpression('/^dd=p:[0-9a-f]{16};t.dm:-0,root=yes,rojo=00f067aa0ba902b7,congo=t61rcWkgMzE$/', (string)$parent->getContext()->getTraceState());
            $parent->setAttributes([
                '_dd.p.some_val' => 'tehehe'
            ]);
            $this->assertRegularExpression('/^dd=p:[0-9a-f]{16};t.dm:-0;t.some_val:tehehe,root=yes,rojo=00f067aa0ba902b7,congo=t61rcWkgMzE$/', (string)$parent->getContext()->getTraceState());
            try {
                $child = $tracer->spanBuilder("child")->startSpan(); // local parent sampler will be used

                $childContext = $child->getContext();
                $this->assertSame($parent->getContext()->getTraceId(), $childContext->getTraceId());
                $this->assertSame($parent->getContext()->getSpanId(), $child->getParentContext()->getSpanId());
                $this->assertFalse($childContext->isRemote()); // "When creating children from remote spans, their IsRemote flag MUST be set to false."
                $this->assertEquals(1, $childContext->getTraceFlags()); // RECORD_AND_SAMPLED ==> 01 (AlwaysOn sampler)
                $this->assertRegularExpression('/^dd=p:[0-9a-f]{16};t.dm:-0,localparent=yes,rojo=00f067aa0ba902b7,congo=t61rcWkgMzE$/', (string)$childContext->getTraceState());
                $this->assertRegularExpression('/^dd=p:[0-9a-f]{16};t.dm:-0;t.some_val:tehehe,root=yes,rojo=00f067aa0ba902b7,congo=t61rcWkgMzE$/', (string)$parent->getContext()->getTraceState());

                $grandChild = $tracer->spanBuilder("grandChild")
                    ->setParent(Context::getCurrent()->withContextValue($child))
                    ->startSpan();
                $grandChildScope = $grandChild->activate();

                $grandChildContext = $grandChild->getContext();
                $this->assertSame($parent->getContext()->getTraceId(), $grandChildContext->getTraceId());
                $this->assertSame($child->getContext()->getSpanId(), $grandChild->getParentContext()->getSpanId());
                $this->assertFalse($grandChildContext->isRemote()); // "When creating children from remote spans, their IsRemote flag MUST be set to false."
                $this->assertEquals(1, $grandChildContext->getTraceFlags()); // RECORD_AND_SAMPLED ==> 01 (AlwaysOn sampler)
                $this->assertRegularExpression('/^dd=p:[0-9a-f]{16};t.dm:-0,localparent=yes,rojo=00f067aa0ba902b7,congo=t61rcWkgMzE$/', (string)$grandChildContext->getTraceState());

                $grandChildScope->detach();
                $grandChild->end();

                $child->end();
            } finally {
                $this->assertRegularExpression('/^dd=p:[0-9a-f]{16};t.dm:-0;t.some_val:tehehe,root=yes,rojo=00f067aa0ba902b7,congo=t61rcWkgMzE$/', (string)$parent->getContext()->getTraceState());
                $scope->detach();
                $parent->end();
            }
        });

        $spans = $traces[0];
        list($parentSpan, $childSpan) = $spans;
        $this->assertNotEmpty($parentSpan['trace_id']);
        $this->assertSame($parentSpan['trace_id'], $parentSpan['span_id']);
        $this->assertSame($parentSpan['trace_id'], $childSpan['trace_id']);
        $this->assertSame($parentSpan['span_id'], $childSpan['trace_id']);
        $this->assertNotSame($parentSpan['span_id'], $childSpan['span_id']);
        $this->assertNotSame($childSpan['trace_id'], $childSpan['span_id']);
    }

    public function testMultipleTraceStateRemote()
    {
        $traces = $this->isolateTracer(function () {
            $root = new class() implements SamplerInterface {
                public function shouldSample(
                    ContextInterface $parentContext,
                    string $traceId,
                    string $spanName,
                    int $spanKind,
                    AttributesInterface $attributes,
                    array $links
                ): SamplingResult {
                    return new SamplingResult(
                        SamplingResult::RECORD_AND_SAMPLE,
                        [],
                        new TraceState("root=yes,rojo=00f067aa0ba902b7,congo=t61rcWkgMzE")
                    );
                }

                public function getDescription(): string
                {
                    return "Custom Sampler";
                }
            };

            $remoteParentSampler = new class() implements SamplerInterface {
                public function shouldSample(
                    ContextInterface $parentContext,
                    string $traceId,
                    string $spanName,
                    int $spanKind,
                    AttributesInterface $attributes,
                    array $links
                ): SamplingResult {
                    return new SamplingResult(
                        SamplingResult::RECORD_AND_SAMPLE,
                        [],
                        new TraceState("remoteparent=yes,rojo=00f067aa0ba902b7,congo=t61rcWkgMzE")
                    );
                }

                public function getDescription(): string
                {
                    return "Custom Sampler";
                }
            };

            $remoteParentNotSampler = new class() implements SamplerInterface {
                public function shouldSample(
                    ContextInterface $parentContext,
                    string $traceId,
                    string $spanName,
                    int $spanKind,
                    AttributesInterface $attributes,
                    array $links
                ): SamplingResult {
                    return new SamplingResult(
                        SamplingResult::RECORD_AND_SAMPLE,
                        [],
                        new TraceState("remoteparentnot=yes,rojo=00f067aa0ba902b7,congo=t61rcWkgMzE")
                    );
                }

                public function getDescription(): string
                {
                    return "Custom Sampler";
                }
            };

            // Create a new tracer with a parent based sampling
            $tracer = (new TracerProvider([], new ParentBased(
                $root,
                $remoteParentSampler,
                $remoteParentNotSampler
            )))->getTracer('OpenTelemetry.TracerTest');
            $remoteContext =  SpanContext::createFromRemoteParent(
                '4bf92f3577b34da6a3ce929d0e0e4736',
                '00f067aa0ba902b7',
                TraceFlags::SAMPLED,
                new TraceState("rojo=00f067aa0ba902b7,congo=t61rcWkgMzE")
            );
            $remoteSpan = new NonRecordingSpan($remoteContext);

            $child = $tracer->spanBuilder("child")
                ->setParent(Context::getCurrent()->withContextValue($remoteSpan))
                ->startSpan();
            $scope = $child->activate();

            $childContext = $child->getContext();
            $this->assertSame($remoteContext->getTraceId(), $childContext->getTraceId());
            $this->assertSame($remoteContext->getSpanId(), $child->getParentContext()->getSpanId());
            $this->assertFalse($childContext->isRemote()); // "When creating children from remote spans, their IsRemote flag MUST be set to false."
            $this->assertEquals(1, $childContext->getTraceFlags()); // RECORD_AND_SAMPLED ==> 01 (AlwaysOn sampler)
            //$this->assertSame("dd=p:[0-9a-f]{16};t.dm:-0,remoteparent=yes,rojo=00f067aa0ba902b7,congo=t61rcWkgMzE", (string)$childContext->getTraceState());

            $tracer = self::getTracer();
            $grandChild = $tracer->spanBuilder("grandChild")
                ->setParent(Context::getCurrent()->withContextValue($child))
                ->startSpan();
            $expected_tracestate = "dd=p:" . $childContext->getSpanId() . ";t.dm:-0,remoteparent=yes,rojo=00f067aa0ba902b7,congo=t61rcWkgMzE";
            $this->assertSame($expected_tracestate, (string)$child->getContext()->getTraceState());
            $grandChildScope = $grandChild->activate();

            $grandChildContext = $grandChild->getContext();
            $this->assertSame($remoteContext->getTraceId(), $grandChildContext->getTraceId());
            $this->assertSame($child->getContext()->getSpanId(), $grandChild->getParentContext()->getSpanId());
            $this->assertFalse($grandChildContext->isRemote()); // "When creating children from remote spans, their IsRemote flag MUST be set to false."
            $this->assertEquals(1, $grandChildContext->getTraceFlags()); // RECORD_AND_SAMPLED ==> 01 (AlwaysOn sampler)
            $expected_tracestate = "dd=p:" . $childContext->getSpanId() . ";t.dm:-0,remoteparent=yes,rojo=00f067aa0ba902b7,congo=t61rcWkgMzE";
            $this->assertSame($expected_tracestate, (string)$child->getContext()->getTraceState());
            $expected_tracestate = "dd=p:" . $grandChildContext->getSpanId(). ";t.dm:-0,remoteparent=yes,rojo=00f067aa0ba902b7,congo=t61rcWkgMzE";
            $this->assertSame($expected_tracestate, (string)$grandChildContext->getTraceState());

            $grandChildScope->detach();
            $grandChild->end();

            try {
                $child->end();
            } finally {
                $scope->detach();
            }
        });

        $spans = $traces[0];
        list($childSpan) = $spans;
        $this->assertSame("11803532876627986230", $childSpan['trace_id']);
        $this->assertSame("4bf92f3577b34da6", $childSpan['meta']['_dd.p.tid']);
        $this->assertSame("67667974448284343", $childSpan['parent_id']);
    }

    public function testAddItemToTracestate()
    {
        // See https://github.com/open-telemetry/opentelemetry-java/discussions/4008
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $span = $tracer->spanBuilder('test.span')
                ->setSpanKind(SpanKind::KIND_INTERNAL)
                ->startSpan();

            $span->setAttributes([
                '_dd.p.congo' => 't61rcWkgMzE',
            ]);

            $this->assertRegularExpression('/^dd=p:[0-9a-f]{16};t.congo:t61rcWkgMzE;t.dm:-0$/', (string)$span->getContext()->getTraceState());

            $traceState = $span->getContext()->getTraceState()->with('rojo', '00f067aa0ba902b7');
            $context = SpanContext::create(
                $span->getContext()->getTraceId(),
                $span->getContext()->getSpanId(),
                $span->getContext()->getTraceFlags(),
                $traceState
            );

            $child = $tracer->spanBuilder("child")
                ->setParent(Context::getCurrent()->withContextValue(Span::wrap($context)))
                ->startSpan();

            $this->assertRegularExpression('/^dd=p:[0-9a-f]{16};t.congo:t61rcWkgMzE;t.dm:-0,rojo=00f067aa0ba902b7$/', (string)$child->getContext()->getTraceState());

            $child->end();
            $span->end();
        });

        $span = $traces[0];
        list($childSpan, $span) = $span;

        $this->assertSame('t61rcWkgMzE', $span['meta']['_dd.p.congo']);
        $this->assertSame('t61rcWkgMzE', $childSpan['meta']['_dd.p.congo']);
        $this->assertSame('-0', $span['meta']['_dd.p.dm']);
        $this->assertSame('-0', $childSpan['meta']['_dd.p.dm']);

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('internal', 'test.span')
                ->withChildren([
                    SpanAssertion::exists('internal', 'child')
                ])
        ]);
    }

    public function testUpdateOperationNameOnTheFly()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $span = $tracer->spanBuilder('operation')
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->startSpan();
            $scopeSpan = $span->activate();

            $this->assertSame('client.request', active_span()->name);

            $span->setAttribute('messaging.system', 'Kafka');

            $this->assertSame('client.request', active_span()->name);

            $span->setAttribute('messaging.operation', 'Receive');

            $this->assertSame('kafka.receive', active_span()->name);

            $scopeSpan->detach();
            $span->end();
        });

        $span = $traces[0][0];
        $this->assertSame('kafka.receive', $span['name']);
        $this->assertSame('operation', $span['resource']);
    }
}
