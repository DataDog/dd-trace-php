<?php

namespace DDTrace\Tests\OpenTelemetry\Integration\API;

use DDTrace\Log\Logger;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\Tag;
use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\TracerTestTrait;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\NonRecordingSpan;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TraceState;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SamplingResult;
use OpenTelemetry\SDK\Trace\Span;
use OpenTelemetry\SDK\Trace\SpanBuilder;
use OpenTelemetry\SDK\Trace\TracerProvider;
use function DDTrace\active_span;
use function DDTrace\generate_distributed_tracing_headers;

final class TracerTest extends BaseTestCase
{
    use TracerTestTrait, SpanAssertionTrait;

    public function ddSetUp(): void
    {
        \dd_trace_serialize_closed_spans();
        self::putEnv("DD_TRACE_GENERATE_ROOT_SPAN=0");
        //self::putEnv("DD_TRACE_DEBUG=1");
        parent::ddSetUp();

        $tracerProvider = new TracerProvider([], new AlwaysOnSampler());
        Sdk::builder()
            ->setTracerProvider($tracerProvider)
            ->buildAndRegisterGlobal();
    }

    public function ddTearDown()
    {
        Context::setStorage(new ContextStorage()); // Reset OpenTelemetry context
        self::putEnv("DD_TRACE_GENERATE_ROOT_SPAN=");
        self::putEnv("DD_TRACE_DEBUG=");
        parent::ddTearDown();
        \dd_trace_serialize_closed_spans();
    }

    public static function getTracer()
    {
        // Generate a unique key of length 10
        $uniqueKey = substr(md5(uniqid()), 0, 10);
        $tracer = (new TracerProvider([], new AlwaysOnSampler()))->getTracer("OpenTelemetry.TracerTest$uniqueKey");
        return $tracer;
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
            SpanAssertion::build('otel_unknown', 'datadog/dd-trace-tests', 'cli', 'test.span1')
                ->withExistingTagsNames([
                    'service.version',
                    'telemetry.sdk.name',
                    'telemetry.sdk.language',
                    'telemetry.sdk.version',
                    'process.runtime.name',
                    'process.runtime.version',
                    'process.pid',
                    'process.executable.path',
                    'process.command',
                    'process.command_args.0',
                    'process.command_args.1',
                    'process.command_args.2',
                    'process.command_args.3',
                    'process.owner',
                    'os.type',
                    'os.description',
                    'os.name',
                    'os.version',
                    'host.name',
                    'host.arch'
                ]),
            SpanAssertion::build('otel_unknown', 'datadog/dd-trace-tests', 'cli', 'test.span2')
                ->withExistingTagsNames([
                    'service.version',
                    'telemetry.sdk.name',
                    'telemetry.sdk.language',
                    'telemetry.sdk.version',
                    'process.runtime.name',
                    'process.runtime.version',
                    'process.pid',
                    'process.executable.path',
                    'process.command',
                    'process.command_args.0',
                    'process.command_args.1',
                    'process.command_args.2',
                    'process.command_args.3',
                    'process.owner',
                    'os.type',
                    'os.description',
                    'os.name',
                    'os.version',
                    'host.name',
                    'host.arch'
                ]),
            SpanAssertion::build('otel_unknown', 'datadog/dd-trace-tests', 'cli', 'test.span3')
                ->withExistingTagsNames([
                    'service.version',
                    'telemetry.sdk.name',
                    'telemetry.sdk.language',
                    'telemetry.sdk.version',
                    'process.runtime.name',
                    'process.runtime.version',
                    'process.pid',
                    'process.executable.path',
                    'process.command',
                    'process.command_args.0',
                    'process.command_args.1',
                    'process.command_args.2',
                    'process.command_args.3',
                    'process.owner',
                    'os.type',
                    'os.description',
                    'os.name',
                    'os.version',
                    'host.name',
                    'host.arch'
                ])
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
        $this->assertSame('otel_unknown', $span['name']);
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
        $this->assertSame('otel_unknown', $span['name']);
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
        $this->assertSame('otel_unknown', $span['name']);
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
                    "empty-array" => []
                ])
                ->startSpan();
            $span->end();
        });

        $meta = $traces[0][0]['meta'];
        $this->assertSame("a", $meta['string']);
        $this->assertArrayNotHasKey("null-string", $meta);
        $this->assertSame("", $meta['empty_string']);
        $this->assertSame("1", $meta['number']);
        $this->assertSame("true", $meta['boolean']);
        $this->assertSame("a", $meta['string-array.0']);
        $this->assertSame("b", $meta['string-array.1']);
        $this->assertSame("c", $meta['string-array.2']);
        $this->assertSame("true", $meta['boolean-array.0']);
        $this->assertSame("false", $meta['boolean-array.1']);
        $this->assertSame("1.1", $meta['float-array.0']);
        $this->assertSame("2.2", $meta['float-array.1']);
        $this->assertSame("3.3", $meta['float-array.2']);
        $this->assertSame("", $meta['empty-array']);

        $this->markTestIncomplete("Set resource name");
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

    public function testSpanErrorStatus()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $span = $tracer->spanBuilder('test.span')->startSpan();
            $span->setStatus(StatusCode::STATUS_ERROR, "error message");
            $span->end();
        });

        $span = $traces[0][0];
        $this->assertSame('otel_unknown', $span['name']);
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
        $this->assertSame("otel_unknown", $span['name']);
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
        $this->assertSame('otel_unknown', $span['name']);
        $this->assertSame('test.span', $span['resource']);
        $this->assertSame('exception message', $span['meta'][Tag::ERROR_MSG]);
        $this->assertSame('RuntimeException', $span['meta'][Tag::ERROR_TYPE]);
        $this->assertNotEmpty($span['meta'][Tag::ERROR_STACK]);
        $this->assertEquals(1, $span['error']);

        $this->markTestIncomplete("Span Events aren't yet supported");
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
        $this->assertSame('otel_unknown', $span['name']);
        $this->assertSame('test.span', $span['resource']);
        $this->assertSame('error message', $span['meta']['error.message']);
        $this->assertEquals(1, $span['error']);
        $this->assertSame('bar', $span['meta']['foo']);

        $this->markTestIncomplete("Define naming behavior");
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
                        ->setAttribute('http.status_code', 200)
                        ->setAttribute('http.response_content_length', 1024)
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
        $httpSpans = [$spans[1], $spans[2], $spans[3]];
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
            $this->assertSame("dd=t.congo:t61rcWkgMzE;t.some_val:tehehe;t.dm:-1", (string)$span->getContext()->getTraceState());
            $span->end();
            $this->assertSame("dd=t.congo:t61rcWkgMzE;t.some_val:tehehe;t.dm:-1", (string)$span->getContext()->getTraceState());
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
            $this->assertSame("dd=t.tid:4bf92f3577b34da6;t.dm:-0" . ($traceState ? ",$traceState" : ""), (string)$childContext->getTraceState());
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
}
