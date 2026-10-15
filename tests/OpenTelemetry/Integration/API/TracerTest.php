<?php

namespace DDTrace\Tests\OpenTelemetry\Integration\API;

use DDTrace\Sampling\PrioritySampling;
use DDTrace\Tag;
use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\SpanChecker;
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

        $this->assertSame('error_desc', $traces[0]['spans'][0]['attributes']['error.message']);
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

        $span = $traces[0]['spans'][0];
        $this->assertSame(SpanChecker::traceIdLow($traces[0]), $span['span_id']);
        $this->assertNotEquals(0, $span['duration']);
        $this->assertSame('internal', $span['name']);
        $this->assertSame('test.span', $span['resource']);
        $this->assertEquals(PrioritySampling::AUTO_KEEP, $traces[0]['priority']);
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

        $span = $traces[0]['spans'][0];
        $this->assertSame(SpanChecker::traceIdLow($traces[0]), $span['span_id']);
        $this->assertNotEquals(0, $span['duration']);
        $this->assertSame('internal', $span['name']);
        $this->assertSame('test.span', $span['resource']);
        $this->assertEquals(PrioritySampling::AUTO_KEEP, $traces[0]['priority']);
        $this->assertSame('bar', $span['attributes']['foo']);
        $this->assertSame('baz', $span['attributes']['bar']);
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

        $span = $traces[0]['spans'][0];
        $this->assertSame(SpanChecker::traceIdLow($traces[0]), $span['span_id']);
        $this->assertNotEquals(0, $span['duration']);
        $this->assertSame('internal', $span['name']);
        $this->assertSame('test.span', $span['resource']);
        $this->assertEquals(PrioritySampling::AUTO_KEEP, $traces[0]['priority']);
        $this->assertSame('bar', $span['attributes']['foo']);
        $this->assertSame('baz', $span['attributes']['bar']);
        $this->assertSame('bar', $span['attributes']['nested']['foo']);
        $this->assertSame('baz', $span['attributes']['nested']['bar']);
        $this->assertSame('alone', $span['attributes']['nested'][0]);
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

        list($parentSpan, $childSpan) = (new SpanChecker())->flattenTraces($traces);
        foreach ($traces as $trace) {
            $this->assertSame(SpanChecker::traceIdLow($trace), $parentSpan['span_id']);
        }
        $this->assertNotSame($parentSpan['span_id'], $childSpan['span_id']);
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

        list($parentSpan, $childSpan) = (new SpanChecker())->flattenTraces($traces);
        foreach ($traces as $trace) {
            $this->assertSame(SpanChecker::traceIdLow($trace), $parentSpan['span_id']);
        }
        $this->assertNotSame($parentSpan['span_id'], $childSpan['span_id']);
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

        $span = $traces[0]['spans'][0];
        $this->assertSame(SpanChecker::traceIdLow($traces[0]), $span['span_id']);
        $this->assertArrayNotHasKey('parent_id', $span);
        $this->assertEquals(PrioritySampling::AUTO_KEEP, $traces[0]['priority']);
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

        list($span, $rootSpan, $childSpan) = (new SpanChecker())->flattenTraces($traces);
        $this->assertSame($rootSpan['span_id'], $childSpan['parent_id']);

        // Both root spans start their own trace, so their span ids are the two trace ids.
        $traceIds = array_map([SpanChecker::class, 'traceIdLow'], $traces);
        $this->assertContains($rootSpan['span_id'], $traceIds);
        $this->assertContains($span['span_id'], $traceIds);
        $this->assertCount(2, array_unique(array_column($traces, 'trace_id')));
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

        $attributes = $traces[0]['spans'][0]['attributes'];
        $this->assertSame("a", $attributes['string']);
        $this->assertArrayNotHasKey("null-string", $attributes);
        $this->assertSame("", $attributes['empty_string']);
        $this->assertEquals(1, $attributes['number']);
        $this->assertTrue($attributes['boolean']);
        $this->assertSame("a", $attributes['string-array'][0]);
        $this->assertSame("b", $attributes['string-array'][1]);
        $this->assertSame("c", $attributes['string-array'][2]);
        $this->assertTrue($attributes['boolean-array'][0]);
        $this->assertFalse($attributes['boolean-array'][1]);
        $this->assertEquals("1.1", $attributes['float-array'][0]);
        $this->assertEquals("2.2", $attributes['float-array'][1]);
        $this->assertEquals("3.3", $attributes['float-array'][2]);
        $this->assertSame("", $attributes['empty-array']);
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

        $span = $traces[0]['spans'][0];
        $this->assertSame($tagSpanKind, $span['span_kind']);
    }

    public function providerSpanKind()
    {
        // V1 introspection promotes span.kind to the int enum: Internal=1,Server=2,Client=3,Producer=4,Consumer=5
        return [
            [SpanKind::KIND_CLIENT, 3],
            [SpanKind::KIND_SERVER, 2],
            [SpanKind::KIND_PRODUCER, 4],
            [SpanKind::KIND_CONSUMER, 5],
            [SpanKind::KIND_INTERNAL, 1],
        ];
    }

    public function providerHttpStatusCode()
    {
        return [
            'numeric http.status_code' => [['http.status_code' => 200], ['http.status_code' => '200']],
            'string http.status_code' => [['http.status_code' => '201'], ['http.status_code' => '201']],
            'numeric http.response.status_code' => [['http.response.status_code' => 300], ['http.status_code' => '300']],
            // Only the numeric (semconv) form is remapped
            'string http.response.status_code' => [['http.response.status_code' => '300'], ['http.response.status_code' => '300']],
            'response status code set first wins' => [
                ['http.response.status_code' => 300, 'http.status_code' => 302],
                ['http.status_code' => '300'],
            ],
            'response status code set last wins' => [
                ['http.status_code' => 302, 'http.response.status_code' => 300],
                ['http.status_code' => '300'],
            ],
        ];
    }

    /**
     * @dataProvider providerHttpStatusCode
     */
    public function testHttpStatusCodeRemapping($attributes, $expected)
    {
        $traces = $this->isolateTracer(function () use ($attributes) {
            $span = self::getTracer()->spanBuilder('test.span')->startSpan();
            foreach ($attributes as $key => $value) {
                $span->setAttribute($key, $value);
            }
            $span->end();
        });

        $actual = array_intersect_key(
            $traces[0]['spans'][0]['attributes'],
            ['http.status_code' => true, 'http.response.status_code' => true]
        );
        $this->assertSame($expected, $actual);
    }

    public function testSpanErrorStatus()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $span = $tracer->spanBuilder('test.span')->startSpan();
            $span->setStatus(StatusCode::STATUS_ERROR, "error message");
            $span->end();
        });

        $span = $traces[0]['spans'][0];
        $this->assertSame('internal', $span['name']);
        $this->assertSame('test.span', $span['resource']);
        $this->assertSame('error message', $span['attributes']['error.message']);
        $this->assertTrue($span['error']);
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

        $span = $traces[0]['spans'][0];
        $this->assertArrayNotHasKey("error", $span);
        $this->assertArrayNotHasKey("error.message", $span['attributes']);
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

        $span = $traces[0]['spans'][0];
        $this->assertSame('internal', $span['name']);
        $this->assertSame('test.span', $span['resource']);
        $this->assertNotEmpty($span['attributes'][Tag::ERROR_STACK]);
    }

    public function testSpanNameUpdate()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $span = $tracer->spanBuilder('test.span')->startSpan();
            $span->updateName('new.name');
            $span->end();
        });

        $span = $traces[0]['spans'][0];
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

        $span = $traces[0]['spans'][0];
        $this->assertSame('internal', $span['name']);
        $this->assertSame('test.span', $span['resource']);
        $this->assertSame('error message', $span['attributes']['error.message']);
        $this->assertTrue($span['error']);
        $this->assertSame('bar', $span['attributes']['foo']);
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

        $spans = (new SpanChecker())->flattenTraces($traces);
        $rootSpan = $spans[0];
        $httpSpans = [$spans[3], $spans[2], $spans[1]];
        $this->assertCount(4, $spans);

        foreach ($traces as $trace) {
            $this->assertSame(SpanChecker::traceIdLow($trace), $rootSpan['span_id']);
        }
        for ($i = 1; $i <= 3; $i++) {
            $httpSpan = $httpSpans[$i - 1];
            $this->assertSame($rootSpan['span_id'], $httpSpan['parent_id']);

            $this->assertSame("http-$i", $httpSpan['resource']);
            $this->assertSame("GET", $httpSpan['attributes']['http.method']);
            $this->assertSame("example.com/$i", $httpSpan['attributes']['http.url']);
            $this->assertSame('200', $httpSpan['attributes']['http.status_code']);
            $this->assertSame('1024', $httpSpan['attributes']['http.response_content_length']);
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
            $this->assertRegularExpression('/^dd=p:[0-9a-f]{16};t.congo:t61rcWkgMzE;t.some_val:tehehe;t.dm:-0,ot=rv:[0-9a-f]{14};th:0$/', (string)$span->getContext()->getTraceState());
            $span->end();
            $this->assertRegularExpression('/^dd=p:[0-9a-f]{16};t.congo:t61rcWkgMzE;t.some_val:tehehe;t.dm:-0,ot=rv:[0-9a-f]{14};th:0$/', (string)$span->getContext()->getTraceState());
        });

        $span = $traces[0]['spans'][0];
        $this->assertSame('t61rcWkgMzE', $span['attributes']['_dd.p.congo']);
        $this->assertSame('tehehe', $span['attributes']['_dd.p.some_val']);
    }

    /**
     * @dataProvider providerRemoteParent
     */
    public function testGetSpanContextWithRemoteParent(int $traceFlags, ?TraceState $traceState)
    {
        $decSpanId = "67667974448284343";

        $traces = $this->isolateTracer(function () use ($traceFlags, $traceState, $decSpanId) {
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

        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $traces[0]['trace_id']);
        $this->assertSame($decSpanId, $traces[0]['spans'][0]['parent_id']);
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
            $this->assertRegularExpression('/^dd=p:[0-9a-f]{16};t.dm:-0,ot=rv:[0-9a-f]{14};th:0,root=yes,rojo=00f067aa0ba902b7,congo=t61rcWkgMzE$/', (string)$parent->getContext()->getTraceState());
            $parent->setAttributes([
                '_dd.p.some_val' => 'tehehe'
            ]);
            $this->assertRegularExpression('/^dd=p:[0-9a-f]{16};t.dm:-0;t.some_val:tehehe,ot=rv:[0-9a-f]{14};th:0,root=yes,rojo=00f067aa0ba902b7,congo=t61rcWkgMzE$/', (string)$parent->getContext()->getTraceState());
            try {
                $child = $tracer->spanBuilder("child")->startSpan(); // local parent sampler will be used

                $childContext = $child->getContext();
                $this->assertSame($parent->getContext()->getTraceId(), $childContext->getTraceId());
                $this->assertSame($parent->getContext()->getSpanId(), $child->getParentContext()->getSpanId());
                $this->assertFalse($childContext->isRemote()); // "When creating children from remote spans, their IsRemote flag MUST be set to false."
                $this->assertEquals(1, $childContext->getTraceFlags()); // RECORD_AND_SAMPLED ==> 01 (AlwaysOn sampler)
                $this->assertRegularExpression('/^dd=p:[0-9a-f]{16};t.dm:-0,localparent=yes,rojo=00f067aa0ba902b7,congo=t61rcWkgMzE$/', (string)$childContext->getTraceState());
                $this->assertRegularExpression('/^dd=p:[0-9a-f]{16};t.dm:-0;t.some_val:tehehe,ot=rv:[0-9a-f]{14};th:0,root=yes,rojo=00f067aa0ba902b7,congo=t61rcWkgMzE$/', (string)$parent->getContext()->getTraceState());

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
                $this->assertRegularExpression('/^dd=p:[0-9a-f]{16};t.dm:-0;t.some_val:tehehe,ot=rv:[0-9a-f]{14};th:0,root=yes,rojo=00f067aa0ba902b7,congo=t61rcWkgMzE$/', (string)$parent->getContext()->getTraceState());
                $scope->detach();
                $parent->end();
            }
        });

        list($parentSpan, $childSpan) = (new SpanChecker())->flattenTraces($traces);
        foreach ($traces as $trace) {
            $this->assertSame(SpanChecker::traceIdLow($trace), $parentSpan['span_id']);
        }
        $this->assertNotSame($parentSpan['span_id'], $childSpan['span_id']);
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

        $this->assertSame("4bf92f3577b34da6a3ce929d0e0e4736", $traces[0]['trace_id']);
        $this->assertSame("67667974448284343", $traces[0]['spans'][0]['parent_id']);
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

            $this->assertRegularExpression('/^dd=p:[0-9a-f]{16};t.congo:t61rcWkgMzE;t.dm:-0,ot=rv:[0-9a-f]{14};th:0$/', (string)$span->getContext()->getTraceState());

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

            $this->assertRegularExpression('/^dd=p:[0-9a-f]{16};t.congo:t61rcWkgMzE;t.dm:-0,ot=rv:[0-9a-f]{14};th:0,rojo=00f067aa0ba902b7$/', (string)$child->getContext()->getTraceState());

            $child->end();
            $span->end();
        });

        list($childSpan, $span) = (new SpanChecker())->flattenTraces($traces);

        $this->assertSame('t61rcWkgMzE', $span['attributes']['_dd.p.congo']);
        $this->assertSame('t61rcWkgMzE', $childSpan['attributes']['_dd.p.congo']);
        foreach ($traces as $trace) {
            $this->assertSame(0, $trace['sampling_mechanism']); // _dd.p.dm '-0' is sampling_mechanism 0
        }

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

        $span = $traces[0]['spans'][0];
        $this->assertSame('kafka.receive', $span['name']);
        $this->assertSame('operation', $span['resource']);
    }
}
