<?php

namespace DDTrace\Tests\OpenTelemetry\Integration;

use DDTrace\HookData;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\TracerTestTrait;
use Fiber;
use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextValidator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\Extension\Propagator\B3\B3Propagator;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\TracerProvider;
use function DDTrace\active_span;
use function DDTrace\close_span;
use function DDTrace\close_spans_until;
use function DDTrace\start_span;
use function DDTrace\start_trace_span;
use function DDTrace\trace_id;

final class InteroperabilityTest extends BaseTestCase
{
    use TracerTestTrait, SpanAssertionTrait;

    // TODO: Implement AttributesBuilder and add a method to retrieve the attributeCountLimit

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
        self::putEnv("DD_TRACE_GENERATE_ROOT_SPAN=");
        //self::putEnv("DD_TRACE_DEBUG=");
        parent::ddTearDown();
        Context::setStorage(new ContextStorage()); // Reset OpenTelemetry context
    }

    public static function commonTagsList(): array
    {
        return [
            'service.version',
            'telemetry.sdk.name',
            'telemetry.sdk.language',
            'telemetry.sdk.version',
            'process.runtime.name',
            'process.runtime.version',
            'process.pid',
            'process.executable.path',
            'process.owner',
            'os.type',
            'os.description',
            'os.name',
            'os.version',
            'host.name',
            'host.arch'
        ];
    }

    public static function getTracer()
    {
        $tracerProvider = Globals::tracerProvider();
        $tracer = (new TracerProvider([], new AlwaysOnSampler()))->getTracer('OpenTelemetry.TracerTest');
        return $tracer;
    }

    public function testActivateAnAlreadyActiveDatadogSpan()
    {
        $traces = $this->isolateTracer(function () {
            $ddSpan = start_span();
            $ddSpan->name = "dd.span";
            $currentSpan = Span::getCurrent();

            $this->assertNotNull($currentSpan);
            $this->assertSame($ddSpan, $currentSpan->getDDSpan());
            $this->assertSame($ddSpan->hexId(), $currentSpan->getContext()->getSpanId());
            $this->assertSame(\DDTrace\root_span()->traceId, $currentSpan->getContext()->getTraceId());

            // Get current scope
            $currentScope = Context::storage()->scope();
            $this->assertNotNull($currentScope);
            $currentScope->detach();
            $currentSpan = Span::getCurrent();

            // Shouldn't have changed
            $this->assertNotNull($currentSpan);
            $this->assertSame($ddSpan, $currentSpan->getDDSpan());
            $this->assertSame($ddSpan->hexId(), $currentSpan->getContext()->getSpanId());
            $this->assertSame(\DDTrace\root_span()->traceId, $currentSpan->getContext()->getTraceId());

            close_span();
            $currentSpan = Span::getCurrent();
            $this->assertSame(SpanContextValidator::INVALID_SPAN, $currentSpan->getContext()->getSpanId());
            $this->assertSame(SpanContextValidator::INVALID_TRACE, $currentSpan->getContext()->getTraceId());
        });

        $span = $traces[0][0];
        $this->assertSame('dd.span', $span['name']);
        $this->assertArrayNotHasKey('parent_id', $span['meta']);
    }

    /** @noinspection PhpParamsInspection */
    public function testMixingOpenTelemetrylAndDatadogBasic()
    {
        //$this->markTestSkipped("d");
        self::putEnvAndReloadConfig(["DD_TRACE_DEBUG=1"]);

        $traces = $this->isolateTracer(function () {
            $tracer = (new TracerProvider())->getTracer('test.tracer');
            $span = $tracer->spanBuilder("test.span")->startSpan();

            $currentSpan = Span::getCurrent();

            $this->assertNotNull($currentSpan);
            $this->assertSame(SpanContextValidator::INVALID_TRACE, $currentSpan->getContext()->getTraceId());
            $this->assertSame(SpanContextValidator::INVALID_SPAN, $currentSpan->getContext()->getSpanId());
            $this->assertSame(SpanContext::getInvalid(), $currentSpan->getContext());

            $scope = $span->activate();
            $currentSpan = Span::getCurrent();

            $this->assertNotNull($currentSpan);
            $this->assertSame($span, $currentSpan);

            $ddSpan = \DDTrace\start_span();
            $ddSpan->name = "other.span";
            $spanId = $ddSpan->hexId();
            $parentId = $ddSpan->parent->hexId();
            $this->assertSame($span->getContext()->getSpanId(), $parentId);

            $currentSpan = Span::getCurrent();
            $this->assertSame($span->getContext()->getSpanId(), $currentSpan->getParentContext()->getSpanId());
            $currentSpan->setAttributes([
                'foo' => 'bar',
            ]);

            $traceId = \DDTrace\root_span()->traceId;
            $this->assertSame($traceId, $currentSpan->getContext()->getTraceId());
            $spanId = $ddSpan->hexId();
            $this->assertSame($spanId, $currentSpan->getContext()->getSpanId());

            close_span(); // Note that we don't detach the scope
            $scope->detach();
            $span->end();
        });

        $spans = $traces[0];
        $this->assertCount(2, $spans);

        list($parent, $child) = $spans;
        $this->assertSame('otel_unknown', $parent['name']);
        $this->assertSame('test.span', $parent['resource']);
        $this->assertSame('other.span', $child['name']);
        $this->assertSame('other.span', $child['resource']);
        $this->assertSame($parent['span_id'], $child['parent_id']);
        $this->assertSame($parent['trace_id'], $child['trace_id']);
        $this->assertSame('bar', $child['meta']['foo']);
        $this->assertArrayNotHasKey('foo', $parent['meta']);
    }

    public function testActivateSpanWithAnotherActiveNonActivatedDatadogSpan()
    {
        $traces = $this->isolateTracer(function () {
            $ddSpan = start_span();
            $ddSpan->name = "dd.span";

            $tracer = self::getTracer();
            $OTelSpan = $tracer->spanBuilder("otel.span")->startSpan();

            /** @var \OpenTelemetry\SDK\Trace\Span $currentSpan */
            $currentSpan = Span::getCurrent();

            $this->assertNotNull($currentSpan);
            $this->assertSame($currentSpan->getDDSpan(), $ddSpan); // The OTel span wasn't activated, so the current span is the DDTrace span

            $ddOTelSpan = $currentSpan;
            $OTelScope = $OTelSpan->activate();

            /** @var \OpenTelemetry\SDK\Trace\Span $currentSpan */
            $currentSpan = Span::getCurrent();

            $this->assertNotNull($currentSpan);
            $this->assertSame($OTelSpan, $currentSpan);
            $this->assertSame($ddOTelSpan->getContext()->getSpanId(), $currentSpan->getParentContext()->getSpanId());

            $OTelScope->detach();
            $OTelSpan->end();
            $ddOTelSpan->end();
        });

        $spans = $traces[0];

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('dd.span', 'dd.span')
                ->withChildren([
                    SpanAssertion::exists('otel_unknown', 'otel.span')
                ])
        ]);
    }

    public function testCloseSpansUntilWithOnlyDatadogSpans()
    {
        $traces = $this->isolateTracer(function () {
            $span1 = start_span();
            $span1->name = "dd.span1";
            $span2 = start_span();
            $span2->name = "dd.span2";
            $span3 = start_span();
            $span3->name = "dd.span3";

            $currentSpan = Span::getCurrent(); // Should generate the OTel spans under the hood
            $this->assertSame($span3, $currentSpan->getDDSpan());

            close_spans_until($span1); // Closes And Flush span3 and span2
            // span1 is never flushed since never closed
            $currentSpan = Span::getCurrent(); // span2 and span3 are closed, span3 is still open and should be the active span
            $this->assertSame($span1, $currentSpan->getDDSpan());
        });

        $spans = $traces[0];
        $this->assertCount(2, $spans);

        list($span2, $span3) = $spans;
        $this->assertSame('dd.span2', $span2['name']);
        $this->assertSame('dd.span3', $span3['name']);
        $this->assertSame($span2['span_id'], $span3['parent_id']);
        $this->assertSame($span2['trace_id'], $span3['trace_id']);
    }

    public function testActivateOtelAfterDatadogSpan()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $OTelSpan = $tracer->spanBuilder("otel.span")->startSpan();

            $ddSpan = start_span();
            $ddSpan->name = "dd.span";

            $OTelScope = $OTelSpan->activate();

            $currentSpan = Span::getCurrent();
            $this->assertSame($ddSpan, $currentSpan->getDDSpan());

            $OTelScope->detach();
            $OTelSpan->end();
            Span::getCurrent()->end();
        });

        $spans = $traces[0];
        $this->assertCount(2, $spans);

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('otel_unknown', 'otel.span')
                ->withChildren([
                    SpanAssertion::exists('dd.span', 'dd.span')
                ])
        ]);

        $this->markTestIncomplete("Behavior to ack");
    }

    public function testMixingManualAndOtelInstrumentationBis()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $OTelParentSpan = $tracer->spanBuilder("otel.parent.span")->startSpan();
            $OTelParentScope = $OTelParentSpan->activate();

            $activeSpan = active_span();
            $this->assertNotNull($activeSpan);
            $this->assertSame('otel.parent.span', $activeSpan->resource);
            $this->assertSame($activeSpan->hexId(), $OTelParentSpan->getContext()->getSpanId());

            $ddChildSpan = start_span();
            $ddChildSpan->name = "dd.child.span";

            $ddChildSpanAsOTel = Span::getCurrent();

            $this->assertNotNull($ddChildSpanAsOTel);
            $this->assertSame($ddChildSpan, $ddChildSpanAsOTel->getDDSpan());

            $OTelGrandChildSpan = $tracer->spanBuilder("otel.grandchild.span")->startSpan();
            $OTelGrandChildScope = $OTelGrandChildSpan->activate();

            $activeSpan = active_span();
            $this->assertNotNull($activeSpan);
            $this->assertSame('otel.grandchild.span', $activeSpan->resource);
            $this->assertSame($activeSpan->hexId(), $OTelGrandChildSpan->getContext()->getSpanId());

            $OTelGrandChildScope->detach();
            $OTelGrandChildSpan->end();
            $ddChildSpanAsOTel->end();
            $OTelParentScope->detach();
            $OTelParentSpan->end();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('otel_unknown', 'otel.parent.span')
                ->withChildren([
                    SpanAssertion::exists('dd.child.span', 'dd.child.span')
                        ->withChildren([
                            SpanAssertion::exists('otel_unknown', 'otel.grandchild.span')
                        ])
                ])
        ]);
    }

    public function testStartNewTraces()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $OTelRootSpan = $tracer->spanBuilder("otel.root.span")->startSpan();
            $OTelRootScope = $OTelRootSpan->activate();

            $currentSpan = Span::getCurrent();
            $this->assertNotNull($currentSpan);
            $this->assertSame($OTelRootSpan, $currentSpan);

            $OTelChildSpan = $tracer->spanBuilder("otel.child.span")->startSpan();
            $OTelChildScope = $OTelChildSpan->activate();

            $currentSpan = Span::getCurrent();
            $this->assertNotNull($currentSpan);
            $this->assertSame($OTelChildSpan, $currentSpan);

            $DDChildSpan = start_span();
            $DDChildSpan->name = "dd.child.span";

            $currentSpan = Span::getCurrent();
            $this->assertNotNull($currentSpan);
            $this->assertSame($DDChildSpan, $currentSpan->getDDSpan());

            $DDRootSpan = start_trace_span();
            $DDRootSpan->name = "dd.root.span";

            $DDRootOTelSpan = $tracer->spanBuilder("dd.root.otel.span")->startSpan();
            $DDRootOTelScope = $DDRootOTelSpan->activate();

            $currentSpan = Span::getCurrent();
            $this->assertNotNull($currentSpan);
            $this->assertSame($DDRootOTelSpan, $currentSpan);

            $DDRootChildSpan = start_span();
            $DDRootChildSpan->name = "dd.root.child.span";

            close_span(); // Closes DDRootChildSpan
            $DDRootOTelScope->detach();
            $DDRootOTelSpan->end();
            close_span(); // Closes and flushes DDRootSpan

            close_span(); // Closes DDChildSpan

            $OTelChildScope->detach();
            $OTelChildSpan->end();

            $OTelRootScope->detach();
            $OTelRootSpan->end();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('otel_unknown', 'otel.root.span')
                ->withChildren([
                    SpanAssertion::exists('otel_unknown', 'otel.child.span')
                        ->withChildren([
                            SpanAssertion::exists('dd.child.span', 'dd.child.span')
                        ])
                ]),
            SpanAssertion::exists('dd.root.span', 'dd.root.span')
                ->withChildren([
                    SpanAssertion::exists('otel_unknown', 'dd.root.otel.span')
                        ->withChildren([
                            SpanAssertion::exists('dd.root.child.span', 'dd.root.child.span')
                        ])
                ])
        ]);
    }

    public function testStartNewTracesWithCloseSpansUntil()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $OTelRootSpan = $tracer->spanBuilder("otel.root.span")->startSpan();
            $OTelRootScope = $OTelRootSpan->activate();

            $currentSpan = Span::getCurrent();
            $this->assertNotNull($currentSpan);
            $this->assertSame($OTelRootSpan, $currentSpan);

            $OTelChildSpan = $tracer->spanBuilder("otel.child.span")->startSpan();
            $OTelChildScope = $OTelChildSpan->activate();

            $currentSpan = Span::getCurrent();
            $this->assertNotNull($currentSpan);
            $this->assertSame($OTelChildSpan, $currentSpan);

            $DDChildSpan = start_span();
            $DDChildSpan->name = "dd.child.span";

            $currentSpan = Span::getCurrent();
            $this->assertNotNull($currentSpan);
            $this->assertSame($DDChildSpan, $currentSpan->getDDSpan());

            $DDRootSpan = start_trace_span();
            $DDRootSpan->name = "dd.root.span";

            $DDRootOTelSpan = $tracer->spanBuilder("dd.root.otel.span")->startSpan();
            $DDRootOTelScope = $DDRootOTelSpan->activate();

            $currentSpan = Span::getCurrent();
            $this->assertNotNull($currentSpan);
            $this->assertSame($DDRootOTelSpan, $currentSpan);

            $DDRootChildSpan = $tracer->spanBuilder("dd.root.child.span")->startSpan();
            $DDRootChildScope = $DDRootChildSpan->activate();

            $DDRootChildScope->detach();
            $DDRootOTelScope->detach();
            close_spans_until(null); // Closes DDRootChildSpan, DDRootOTelSpan and DDRootSpan

            close_span(); // Closes DDChildSpan
            $OTelChildScope->detach();
            $OTelRootScope->detach();
            close_spans_until(null); // Closes OTelChildSpan and OTelRootSpan
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('otel_unknown', 'otel.root.span')
                ->withChildren([
                    SpanAssertion::exists('otel_unknown', 'otel.child.span')
                        ->withChildren([
                            SpanAssertion::exists('dd.child.span', 'dd.child.span')
                        ])
                ]),
            SpanAssertion::exists('dd.root.span', 'dd.root.span')
                ->withChildren([
                    SpanAssertion::exists('otel_unknown', 'dd.root.otel.span')
                        ->withChildren([
                            SpanAssertion::exists('otel_unknown', 'dd.root.child.span')
                        ])
                ])
        ]);
    }

    public function testMixingSetParentContext()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $OTelRootSpan = $tracer->spanBuilder("otel.root.span")->startSpan();
            $OTelRootScope = $OTelRootSpan->activate();

            $DDRootSpan = start_trace_span();
            $DDRootSpan->name = "dd.root.span";

            $DDRootSpanContext = Context::getCurrent();

            // Create a new OTel span with the OTel root span as parent
            $OTelChildSpan = $tracer->spanBuilder("otel.child.span")
                ->setParent(Context::getCurrent()->withContextValue($OTelRootSpan))
                ->startSpan();
            $OTelChildScope = $OTelChildSpan->activate();

            $DDChildSpan = start_span();
            $DDChildSpan->name = "dd.child.span";

            // Create a new OTel span with the DD root span as parent
            $OTelGrandChildSpan = $tracer->spanBuilder("otel.grandchild.span")
                ->setParent($DDRootSpanContext)
                ->startSpan();
            $OTelGrandChildScope = $OTelGrandChildSpan->activate();

            // Create a new DD span with the DD child span as parent
            $DDGrandChildSpan = start_span();
            $DDGrandChildSpan->name = "dd.grandchild.span";

            (Span::getCurrent())->end(); // Closes DDGrandChildSpan
            $OTelGrandChildScope->detach();
            $OTelGrandChildSpan->end();

            close_span(); // Closes DDChildSpan
            $OTelChildScope->detach();
            $OTelChildSpan->end();

            (Span::getCurrent())->end(); // Closes DDRootSpan
            $OTelRootScope->detach();
            $OTelRootSpan->end();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('otel_unknown', 'otel.root.span')
                ->withChildren([
                    SpanAssertion::exists('otel_unknown', 'otel.child.span')
                        ->withChildren([
                            SpanAssertion::exists('dd.child.span', 'dd.child.span')
                        ])
                ]),
            SpanAssertion::exists('dd.root.span', 'dd.root.span')
                ->withChildren([
                    SpanAssertion::exists('otel_unknown', 'otel.grandchild.span')
                        ->withChildren([
                            SpanAssertion::exists('dd.grandchild.span', 'dd.grandchild.span')
                        ])
                ])
        ]);
    }

    public function testMixingMultipleTraces()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $OTelTrace1 = $tracer->spanBuilder("otel.trace1")->startSpan();
            $OTelTrace1Scope = $OTelTrace1->activate();
            $OTelChild1 = $tracer->spanBuilder("otel.child1")->startSpan();
            $OTelChild1Scope = $OTelChild1->activate();

            $OTelTrace2 = $tracer->spanBuilder("otel.trace2")->setParent(false)->startSpan();
            $OTelTrace2Scope = $OTelTrace2->activate();
            $DDChild2 = start_span();
            $DDChild2->name = "dd.child2";

            $OTelTrace1->setAttribute('foo1', 'bar1');

            $DDTrace1 = start_trace_span();
            $DDTrace1->name = "dd.trace1";

            //$currentSpan = Span::getCurrent();
            //$this->assertNotNull($currentSpan);
            //$this->assertSame($DDTrace1, $currentSpan->getDDSpan());

            $DDChild1 = start_span();
            $DDChild1->name = "dd.child1";

            $OTelChild1->setAttribute('foo2', 'bar2');
            $OTelChild1->setAttribute(Tag::SERVICE_NAME, 'my.service');

            $DDTrace2 = start_trace_span();
            $DDTrace2->name = "dd.trace2";
            $OTelChild2 = $tracer->spanBuilder("otel.child2")->startSpan();
            $OTelChild2Scope = $OTelChild2->activate();

            $DDTrace1->meta['foo1'] = 'bar1';

            // Add an OTel span to OTelTrace1
            $OTelChild3 = $tracer->spanBuilder("otel.child3")
                ->setParent(Context::getCurrent()->withContextValue($OTelChild1))
                ->startSpan();
            $OTelChild3Scope = $OTelChild3->activate();

            $OTelChild3->setAttribute('foo3', 'bar3');
            $OTelChild3->setAttribute(Tag::RESOURCE_NAME, 'my.resource');

            // Add an OTel span to OTelChild2
            $OTelChild4 = $tracer->spanBuilder("otel.child4")
                ->setParent(Context::getCurrent()->withContextValue($OTelChild2))
                ->startSpan();
            $OTelChild4Scope = $OTelChild4->activate();

            $OTelChild3->setAttribute('foo3', 'bar3');

            $OTelChild4Scope->detach();
            $OTelChild2Scope->detach();
            close_spans_until(null); // Closes DDTrace2
            close_spans_until(null); // Closes DDTrace1
            $OTelTrace2Scope->detach();
            close_spans_until(null); // Closes OTelTrace2
            $OTelChild3Scope->detach();
            $OTelChild3->end();
            $OTelChild1Scope->detach();
            $OTelChild1->end();
            $OTelTrace1Scope->detach();
            $OTelTrace1->end();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('otel_unknown', 'datadog/dd-trace-tests', 'cli', 'otel.trace1')
                ->withExactTags([
                    'foo1' => 'bar1',
                ])
                ->skipTagsLike('/^process\.command.*/')
                ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                ->withChildren(
                    SpanAssertion::build('otel_unknown', 'my.service', 'cli', 'otel.child1')
                        ->withExactTags([
                            'foo2' => 'bar2',
                        ])
                        ->skipTagsLike('/^process\.command.*/')
                        ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                        ->withChildren(
                            SpanAssertion::build('otel_unknown', 'datadog/dd-trace-tests', 'cli', 'my.resource')
                                ->withExactTags([
                                    'foo3' => 'bar3',
                                ])
                                ->skipTagsLike('/^process\.command.*/')
                                ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                        )
                ),
            SpanAssertion::exists('otel_unknown', 'otel.trace2', null, 'datadog/dd-trace-tests')
                ->withChildren(
                    SpanAssertion::exists('dd.child2', 'dd.child2', null, 'datadog/dd-trace-tests')
                ),
            SpanAssertion::build('dd.trace1', 'datadog/dd-trace-tests', 'cli', 'dd.trace1')
                ->withExactTags([
                    'foo1' => 'bar1',
                ])
                ->withChildren(
                    SpanAssertion::build('dd.child1', 'datadog/dd-trace-tests', 'cli', 'dd.child1')
                ),
            SpanAssertion::build('dd.trace2', 'datadog/dd-trace-tests', 'cli', 'dd.trace2')
                ->skipTagsLike('/^process\.command.*/')
                ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                ->withChildren(
                    SpanAssertion::build('otel_unknown', 'datadog/dd-trace-tests', 'cli', 'otel.child2')
                        ->skipTagsLike('/^process\.command.*/')
                        ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                        ->withChildren(
                            SpanAssertion::build('otel_unknown', 'datadog/dd-trace-tests', 'cli', 'otel.child4')
                                ->skipTagsLike('/^process\.command.*/')
                                ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                        )
                ),
        ]);
    }

    public function testW3CInteroperability()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $propagator = TraceContextPropagator::getInstance();

            $carrier = [
                TraceContextPropagator::TRACEPARENT => '00-ff0000000000051791e0000000000041-ff00051791e00041-01'
            ];

            $context = $propagator->extract($carrier);

            $OTelRootSpan = $tracer->spanBuilder("otel.root.span")
                ->setParent($context)
                ->startSpan();
            $OTelRootScope = $OTelRootSpan->activate();

            $DDChildSpan = start_span();
            $DDChildSpan->name = "dd.child.span";

            $DDChildSpanAsOtel = Span::getCurrent();
            $DDChildSpanId = $DDChildSpanAsOtel->getContext()->getSpanId();

            $carrier = [];
            $propagator->inject(
                $carrier,
                null,
                Context::getCurrent()->withContextValue($DDChildSpanAsOtel)
            );

            $DDChildSpanAsOtel->end();
            $OTelRootScope->detach();
            $OTelRootSpan->end();

            $this->assertSame("00-ff0000000000051791e0000000000041-$DDChildSpanId-01", $carrier[TraceContextPropagator::TRACEPARENT]);
            $this->assertSame('dd=t.tid:ff00000000000517;t.dm:-0', $carrier[TraceContextPropagator::TRACESTATE]); // ff00000000000517 is the high 64-bit part of the 128-bit trace id
        });

        $this->assertSame('10511401530282737729', $traces[0][0]['trace_id']);
        $this->assertSame('18374692078461386817', $traces[0][0]['parent_id']);

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('otel_unknown', 'datadog/dd-trace-tests', 'cli', 'otel.root.span')
                ->withExactTags([
                    '_dd.p.tid' => 'ff00000000000517'
                ])
                ->skipTagsLike('/^process\.command.*/')
                ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                ->withChildren(
                    SpanAssertion::build('dd.child.span', 'datadog/dd-trace-tests', 'cli', 'dd.child.span')
                        ->skipTagsLike('/^process\.command.*/')
                        ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                )
        ]);
    }

    public function testB3SingleInteroperability()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $propagator = B3Propagator::getB3SingleHeaderInstance();

            $carrier = [
                'b3' => 'ff0000000000051791e0000000000041-ff00051791e00041'
            ];

            $context = $propagator->extract($carrier);

            $OTelRootSpan = $tracer->spanBuilder("otel.root.span")
                ->setParent($context)
                ->startSpan();
            $OTelRootScope = $OTelRootSpan->activate();

            $DDChildSpan = start_span();
            $DDChildSpan->name = "dd.child.span";

            $DDChildSpanAsOtel = Span::getCurrent();
            $DDChildSpanId = $DDChildSpanAsOtel->getContext()->getSpanId();

            // Inject
            $carrier = [];
            $propagator->inject(
                $carrier,
                null,
                Context::getCurrent()->withContextValue($DDChildSpanAsOtel)
            );

            $DDChildSpanAsOtel->end();
            $OTelRootScope->detach();
            $OTelRootSpan->end();

            $this->assertSame("ff0000000000051791e0000000000041-$DDChildSpanId-1", $carrier['b3']);
        });

        $this->assertSame('10511401530282737729', $traces[0][0]['trace_id']);
        $this->assertSame('18374692078461386817', $traces[0][0]['parent_id']);

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('otel_unknown', 'datadog/dd-trace-tests', 'cli', 'otel.root.span')
                ->withExactTags([
                    '_dd.p.tid' => 'ff00000000000517'
                ])
                ->skipTagsLike('/^process\.command.*/')
                ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                ->withChildren(
                    SpanAssertion::build('dd.child.span', 'datadog/dd-trace-tests', 'cli', 'dd.child.span')
                        ->skipTagsLike('/^process\.command.*/')
                        ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                )
        ]);
    }

    public function testB3MultiInteroperability()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $propagator = B3Propagator::getB3MultiHeaderInstance();

            $carrier = [
                'X-B3-TraceId' => 'ff0000000000051791e0000000000041',
                'X-B3-SpanId' => 'ff00051791e00041',
                'X-B3-Sampled' => '1'
            ];

            $context = $propagator->extract($carrier);

            $OTelRootSpan = $tracer->spanBuilder("otel.root.span")
                ->setParent($context)
                ->startSpan();
            $OTelRootScope = $OTelRootSpan->activate();

            $DDChildSpan = start_span();
            $DDChildSpan->name = "dd.child.span";

            $DDChildSpanAsOtel = Span::getCurrent();
            $DDChildSpanId = $DDChildSpanAsOtel->getContext()->getSpanId();

            // Inject
            $carrier = [];
            $propagator->inject(
                $carrier,
                null,
                Context::getCurrent()->withContextValue($DDChildSpanAsOtel)
            );

            $DDChildSpanAsOtel->end();
            $OTelRootScope->detach();
            $OTelRootSpan->end();

            $this->assertSame('ff0000000000051791e0000000000041', $carrier['X-B3-TraceId']);
            $this->assertSame($DDChildSpanId, $carrier['X-B3-SpanId']);
            $this->assertSame('1', $carrier['X-B3-Sampled']);
        });

        $this->assertSame('10511401530282737729', $traces[0][0]['trace_id']);
        $this->assertSame('18374692078461386817', $traces[0][0]['parent_id']);

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('otel_unknown', 'datadog/dd-trace-tests', 'cli', 'otel.root.span')
                ->withExactTags([
                    '_dd.p.tid' => 'ff00000000000517'
                ])
                ->skipTagsLike('/^process\.command.*/')
                ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                ->withChildren(
                    SpanAssertion::build('dd.child.span', 'datadog/dd-trace-tests', 'cli', 'dd.child.span')
                        ->skipTagsLike('/^process\.command.*/')
                        ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                )
        ]);
    }

    public function testBaggageInteroperability()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = (new TracerProvider())->getTracer('OpenTelemetry.TestTracer');

            $parentSpan = $tracer->spanBuilder('parent')
                ->setSpanKind(SpanKind::KIND_SERVER)
                ->startSpan();
            $parentSpanScope = $parentSpan->activate();

            $baggage = Baggage::getBuilder()
                ->set('user.id', '1')
                ->set('user.name', 'name')
                ->build();
            $baggageScope = $baggage->storeInContext(Context::getCurrent())->activate();

            $childSpan = start_span();
            $childSpan->name = 'child';
            $childSpan->meta['user.id'] = Baggage::getCurrent()->getValue('user.id');

            close_span();

            $parentSpan->setAttribute('http.method', 'GET');
            $parentSpan->setAttribute('http.uri', '/parent');

            $baggageScope->detach();
            $parentSpanScope->detach();
            $parentSpan->end();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('server.request', 'datadog/dd-trace-tests', 'cli', 'parent')
                ->withExactTags([
                    Tag::SPAN_KIND => Tag::SPAN_KIND_VALUE_SERVER,
                    'http.method' => 'GET',
                    'http.uri' => '/parent'
                ])
                ->skipTagsLike('/^process\.command.*/')
                ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                ->withChildren([
                    SpanAssertion::build('child', 'datadog/dd-trace-tests', 'cli', 'child')
                        ->withExactTags([
                            'user.id' => '1',
                        ])
                        ->skipTagsLike('/^process\.command.*/')
                        ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                ])
        ]);
    }
}
