<?php

namespace DDTrace\Tests\OpenTelemetry\Integration\API;

use DDTrace\Log\Logger;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\Tag;
use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tests\Common\TracerTestTrait;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\Span;
use OpenTelemetry\SDK\Trace\TracerProvider;

final class TracerTest extends BaseTestCase
{
    use TracerTestTrait;

    public function ddSetUp(): void
    {
        \dd_trace_serialize_closed_spans();
        self::putEnv("DD_TRACE_GENERATE_ROOT_SPAN=0");
        //self::putEnv("DD_TRACE_DEBUG=1");
        parent::ddSetUp();
        Sdk::builder()
            ->setTracerProvider(new TracerProvider())
            ->buildAndRegisterGlobal();
    }

    public function ddTearDown()
    {
        self::putEnv("DD_TRACE_GENERATE_ROOT_SPAN=");
        self::putEnv("DD_TRACE_DEBUG=");
        parent::ddTearDown();
    }

    public static function getTracer()
    {
        $tracerProvider = Globals::tracerProvider();
        $tracer = $tracerProvider->getTracer('OpenTelemetry.TracerTest');
        return $tracer;
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
        $this->assertSame('test.span', $span['name']);
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
        $this->assertSame('test.span', $span['name']);
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
        $this->assertSame('test.span', $span['name']);
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

    public function testConcurrentSpans()
    {
        self::putEnvAndReloadConfig(["DD_TRACE_DEBUG=1"]);
        // credits: https://github.com/open-telemetry/opentelemetry-php/blob/main/examples/traces/features/concurrent_spans.php
        $traces = $this->isolateTracer(function () {
           $tracer = self::getTracer();

            $rootSpan = $tracer->spanBuilder('root')->startSpan();
            $scope = $rootSpan->activate();

            // Because the root span is active, each of the following spans will be parented to the root span
            try {
                $spans = [];
                for ($i = 1; $i <= 3; $i++) {
                    $currentContext = Context::getCurrent();
                    Logger::get()->debug('Current Context Trace Id: ' . $currentContext->getSpanContext()->getTraceId());
                    Logger::get()->debug('Current Context Span Id: ' . $currentContext->getSpanContext()->getSpanId());
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

        fwrite(STDERR, json_encode($traces[0], JSON_PRETTY_PRINT));
        $spans = $traces[0];

        list($rootSpan, $httpSpans) = $spans;

        fwrite(STDERR, json_encode($httpSpans, JSON_PRETTY_PRINT));

        $traceId = $rootSpan['trace_id'];
        $this->assertNotEmpty($traceId);

        $this->assertSame($traceId, $rootSpan['span_id']);
        for ($i = 1; $i <= 3; $i++) {
            $httpSpan = $httpSpans[$i - 1];
            $this->assertSame($traceId, $httpSpan['trace_id']);
            $this->assertSame($rootSpan['span_id'], $httpSpan['parent_id']);

            $this->assertSame("http-$i", $httpSpan['name']);
            $this->assertSame("GET", $httpSpan['meta']['http.method']);
            $this->assertSame("example.com/$i", $httpSpan['meta']['http.url']);
            $this->assertSame(200, $httpSpan['meta']['http.status_code']);
            $this->assertSame(1024, $httpSpan['meta']['http.response_content_length']);
        }
    }

    // TODO: Span pyramid
    // TODO: All span kinds
}
