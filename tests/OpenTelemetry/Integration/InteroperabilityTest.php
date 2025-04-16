<?php

namespace DDTrace\Tests\OpenTelemetry\Integration;

use DDTrace\SpanLink;
use DDTrace\SpanEvent;
use DDTrace\ExceptionSpanEvent;
use DDTrace\Tag;
use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\TracerTestTrait;
use Fiber;
use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextValidator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TraceState;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextStorage;
use OpenTelemetry\Extension\Propagator\B3\B3Propagator;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\TracerProvider;
use function DDTrace\active_span;
use function DDTrace\close_span;
use function DDTrace\close_spans_until;
use function DDTrace\start_span;
use function DDTrace\start_trace_span;

final class InteroperabilityTest extends BaseTestCase
{
    use TracerTestTrait, SpanAssertionTrait;

    // TODO: Implement AttributesBuilder and add a method to retrieve the attributeCountLimit

    public function ddSetUp(): void
    {
        \dd_trace_serialize_closed_spans();
        self::putEnv("DD_TRACE_GENERATE_ROOT_SPAN=0");
        parent::ddSetUp();
    }

    public function ddTearDown()
    {
        self::putEnv("DD_TRACE_GENERATE_ROOT_SPAN=");
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
        $this->assertSame('internal', $parent['name']);
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
                    SpanAssertion::exists('internal', 'otel.span')
                ])
        ], true, false);
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
            SpanAssertion::exists('internal', 'otel.span')
                ->withChildren([
                    SpanAssertion::exists('dd.span', 'dd.span')
                ])
        ], true, false);
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
            SpanAssertion::exists('internal', 'otel.parent.span')
                ->withChildren([
                    SpanAssertion::exists('dd.child.span', 'dd.child.span')
                        ->withChildren([
                            SpanAssertion::exists('internal', 'otel.grandchild.span')
                        ])
                ])
        ], true, false);
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
            SpanAssertion::exists('internal', 'otel.root.span')
                ->withChildren([
                    SpanAssertion::exists('internal', 'otel.child.span')
                        ->withChildren([
                            SpanAssertion::exists('dd.child.span', 'dd.child.span')
                        ])
                ]),
            SpanAssertion::exists('dd.root.span', 'dd.root.span')
                ->withChildren([
                    SpanAssertion::exists('internal', 'dd.root.otel.span')
                        ->withChildren([
                            SpanAssertion::exists('dd.root.child.span', 'dd.root.child.span')
                        ])
                ])
        ], true, false);
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
            SpanAssertion::exists('internal', 'otel.root.span')
                ->withChildren([
                    SpanAssertion::exists('internal', 'otel.child.span')
                        ->withChildren([
                            SpanAssertion::exists('dd.child.span', 'dd.child.span')
                        ])
                ]),
            SpanAssertion::exists('dd.root.span', 'dd.root.span')
                ->withChildren([
                    SpanAssertion::exists('internal', 'dd.root.otel.span')
                        ->withChildren([
                            SpanAssertion::exists('internal', 'dd.root.child.span')
                        ])
                ])
        ], true, false);
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
            SpanAssertion::exists('internal', 'otel.root.span')
                ->withChildren([
                    SpanAssertion::exists('internal', 'otel.child.span')
                        ->withChildren([
                            SpanAssertion::exists('dd.child.span', 'dd.child.span')
                        ])
                ]),
            SpanAssertion::exists('dd.root.span', 'dd.root.span')
                ->withChildren([
                    SpanAssertion::exists('internal', 'otel.grandchild.span')
                        ->withChildren([
                            SpanAssertion::exists('dd.grandchild.span', 'dd.grandchild.span')
                        ])
                ])
        ], true, false);
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
            SpanAssertion::exists('internal', 'otel.trace1', null, 'datadog/dd-trace-tests')
                ->withExistingTagsNames(['foo1'])
                ->withChildren(
                    SpanAssertion::exists('internal', 'otel.child1', null, 'my.service')
                        ->withExistingTagsNames(['foo2'])
                        ->withChildren(
                            SpanAssertion::exists('internal', 'my.resource', null, 'datadog/dd-trace-tests')
                                ->withExistingTagsNames(['foo3'])
                        )
                ),
            SpanAssertion::exists('internal', 'otel.trace2', null, 'datadog/dd-trace-tests')
                ->withChildren(
                    SpanAssertion::exists('dd.child2', 'dd.child2', null, 'datadog/dd-trace-tests')
                ),
            SpanAssertion::exists('dd.trace1', 'dd.trace1', null, 'datadog/dd-trace-tests')
                ->withExistingTagsNames(['foo1'])
                ->withChildren(
                    SpanAssertion::exists('dd.child1', 'dd.child1', null, 'datadog/dd-trace-tests')
                ),
            SpanAssertion::exists('dd.trace2', 'dd.trace2', null, 'datadog/dd-trace-tests')
                ->withChildren(
                    SpanAssertion::exists('internal', 'otel.child2', null, 'datadog/dd-trace-tests')
                        ->withChildren(
                            SpanAssertion::exists('internal', 'otel.child4', null, 'datadog/dd-trace-tests')
                        )
                ),
        ], true, false);
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
            $this->assertSame("dd=p:$DDChildSpanId;t.dm:-0", $carrier[TraceContextPropagator::TRACESTATE]); // ff00000000000517 is the high 64-bit part of the 128-bit trace id
        });

        $this->assertSame('10511401530282737729', $traces[0][0]['trace_id']);
        $this->assertSame('18374692078461386817', $traces[0][0]['parent_id']);

        $otelRootSpan = $traces[0][0];
        $this->assertSame('ff00000000000517', $otelRootSpan['meta']['_dd.p.tid']);

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('internal', 'otel.root.span', false, 'phpunit')
                ->withChildren(
                    SpanAssertion::exists('dd.child.span', 'dd.child.span', false, 'phpunit')
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

        $otelRootSpan = $traces[0][0];
        $this->assertSame('ff00000000000517', $otelRootSpan['meta']['_dd.p.tid']);

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('internal', 'otel.root.span', false, 'phpunit')
                ->withChildren(
                    SpanAssertion::exists('dd.child.span', 'dd.child.span', false, 'phpunit')
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

        $otelRootSpan = $traces[0][0];
        $this->assertSame('ff00000000000517', $otelRootSpan['meta']['_dd.p.tid']);

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('internal', 'otel.root.span', false, 'phpunit')
                ->withChildren(
                    SpanAssertion::exists('dd.child.span', 'dd.child.span', false, 'phpunit')
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

        list($parent, $child) = $traces[0];
        $this->assertSame(Tag::SPAN_KIND_VALUE_SERVER, $parent['meta'][Tag::SPAN_KIND]);
        $this->assertSame('GET', $parent['meta']['http.method']);
        $this->assertSame('/parent', $parent['meta']['http.uri']);
        $this->assertSame('1', $child['meta']['user.id']);

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('server.request', 'parent', false, 'datadog/dd-trace-tests')
                ->withChildren([
                    SpanAssertion::exists('child', 'child', false, 'datadog/dd-trace-tests')
                ])
        ], true, false);
    }

    public function testSpecialAttributes()
    {
        $traces = $this->isolateTracer(function () {
            $tracer = self::getTracer();
            $span = $tracer->spanBuilder('otel.span')
                ->setSpanKind(SpanKind::KIND_SERVER)
                ->startSpan();
            $span->setAttributes([
                'http.request.method' => 'GET',
                'resource.name' => 'new.name',
                'operation.name' => 'Overriden.name',
                'service.name' => 'new.service.name',
                'span.type' => 'new.span.type',
                'analytics.event' => 'true',
            ]);

            $span->end();
        });

        $this->assertCount(1, $traces[0]);
        $span = $traces[0][0];
        $this->assertSame('overriden.name', $span['name']);
        $this->assertSame('new.name', $span['resource']);
        $this->assertSame('new.service.name', $span['service']);
        $this->assertSame('new.span.type', $span['type']);
        $this->assertEquals(1.0, $span['metrics']['_dd1.sr.eausr']);
    }

    public function testHasEnded()
    {
        $this->isolateTracer(function () {
            start_span();
            $otelSpan = Span::getCurrent();
            close_span();
            $this->assertTrue($otelSpan->hasEnded());
        });
    }

    public function testAttributesInteroperability()
    {
        $traces = $this->isolateTracer(function () {
            $span = start_span();
            $span->name = "dd.span";
            /** @var \OpenTelemetry\SDK\Trace\Span $otelSpan */
            $span->meta['arg1'] = 'value1';
            $span->meta['arg2'] = 'value2';
            $otelSpan = Span::getCurrent();
            $otelSpan->setAttribute('arg5', 'value5');
            $otelSpan->setAttribute('arg6', 'value6');
            $otelSpan->setAttribute('m1', 1);
            $span->metrics['m2'] = 2;
            unset($span->meta['arg1']); // Removes the arg1 -> value1 pair
            $this->assertNull($otelSpan->getAttribute('arg1'));
            $span->meta['arg3'] = 'value3';
            $otelSpan->setAttribute('key', 'value');
            $otelSpan->setAttribute('arg5', null); // Removes the arg5 -> value5 pair
            close_span(); // Not flushed, yet
            $span->meta['arg4'] = 'value4'; // Added
            $otelSpan->setAttribute('post', 'val'); // Not Added (purposely)

            $this->assertTrue($otelSpan->hasEnded());
            $currentTime = (int) (microtime(true) * 1e9);
            $this->assertNotEquals(0, $otelSpan->toSpanData()->getEndEpochNanos());
            $this->assertLessThanOrEqual($currentTime, $otelSpan->toSpanData()->getEndEpochNanos());

            $attributes = $otelSpan->toSpanData()->getAttributes()->toArray();
            $this->assertArrayNotHasKey('arg1', $attributes);
            $this->assertSame('value2', $attributes['arg2']);
            $this->assertSame('value3', $attributes['arg3']);
            $this->assertSame('value4', $attributes['arg4']);
            $this->assertArrayNotHasKey('arg5', $attributes);
            $this->assertSame('value6', $attributes['arg6']);
            $this->assertSame('value', $attributes['key']);
            $this->assertArrayNotHasKey('post', $attributes);

            $this->assertEquals(1, $attributes['m1']);
            $this->assertEquals(2, $attributes['m2']);
        });

        $this->assertCount(1, $traces[0]);

        $meta = $traces[0][0]['meta'];
        $this->assertArrayNotHasKey('arg1', $meta);
        $this->assertSame('value2', $meta['arg2']);
        $this->assertSame('value3', $meta['arg3']);
        $this->assertSame('value4', $meta['arg4']);
        $this->assertArrayNotHasKey('arg5', $meta);
        $this->assertSame('value6', $meta['arg6']);
        $this->assertSame('value', $meta['key']);
        $this->assertArrayNotHasKey('post', $meta);

        $this->assertEquals(1, $traces[0][0]['metrics']['m1']);
        $this->assertEquals(2, $traces[0][0]['metrics']['m2']);
    }

    public function testSpanLinksInteroperabilityFromDatadogSpan()
    {
        $traces = $this->isolateTracer(function () {
            $span = start_span();
            $span->name = "dd.span";

            $spanLink = new SpanLink();
            $spanLink->traceId = "ff0000000000051791e0000000000041";
            $spanLink->spanId = "ff00000000000517";
            $spanLink->traceState = "dd=t.dm:-0";
            $spanLink->attributes = [
                'arg1' => 'value1',
                'arg2' => 'value2',
            ];
            $span->links[] = $spanLink;

            /** @var \OpenTelemetry\SDK\Trace\Span $OTelSpan */
            $OTelSpan = Span::getCurrent();
            $OTelSpanLink = $OTelSpan->toSpanData()->getLinks()[0];
            $OTelSpanLinkContext = $OTelSpanLink->getSpanContext();

            $this->assertSame('ff0000000000051791e0000000000041', $OTelSpanLinkContext->getTraceId());
            $this->assertSame('ff00000000000517', $OTelSpanLinkContext->getSpanId());
            $this->assertSame('dd=t.dm:-0', (string) $OTelSpanLinkContext->getTraceState());

            $this->assertSame([
                'arg1' => 'value1',
                'arg2' => 'value2',
            ], $OTelSpanLink->getAttributes()->toArray());

            close_span();
        });

        $this->assertCount(1, $traces[0]);
        $this->assertSame("[{\"trace_id\":\"ff0000000000051791e0000000000041\",\"span_id\":\"ff00000000000517\",\"trace_state\":\"dd=t.dm:-0\",\"attributes\":{\"arg1\":\"value1\",\"arg2\":\"value2\"}}]", $traces[0][0]['meta']['_dd.span_links']);
    }

    public function testBasicSpanLinksFromDatadog()
    {
        $traces = $this->isolateTracer(function () {
            $span = start_span();
            $span->name = "dd.span";

            $spanLink = new SpanLink();
            $spanLink->traceId = "ff0000000000051791e0000000000041";
            $spanLink->spanId = "ff00000000000517";
            $span->links[] = $spanLink;

            /** @var \OpenTelemetry\SDK\Trace\Span $OTelSpan */
            $OTelSpan = Span::getCurrent();
            $OTelSpanLink = $OTelSpan->toSpanData()->getLinks()[0];
            $OTelSpanLinkContext = $OTelSpanLink->getSpanContext();

            $this->assertSame('ff0000000000051791e0000000000041', $OTelSpanLinkContext->getTraceId());
            $this->assertSame('ff00000000000517', $OTelSpanLinkContext->getSpanId());

            close_span();
        });

        $this->assertCount(1, $traces[0]);
        $this->assertSame("[{\"trace_id\":\"ff0000000000051791e0000000000041\",\"span_id\":\"ff00000000000517\"}]", $traces[0][0]['meta']['_dd.span_links']);
    }

    public function testSpanLinksInteroperabilityFromOpenTelemetrySpan()
    {
        $sampledSpanContext = SpanContext::create(
            '12345678876543211234567887654321',
            '8765432112345678',
            TraceFlags::SAMPLED,
            new TraceState('dd=t.dm:-0')
        );

        $traces = $this->isolateTracer(function () use ($sampledSpanContext) {
            $otelSpan = self::getTracer()->spanBuilder("otel.span")
                ->addLink($sampledSpanContext, ['arg1' => 'value1'])
                ->startSpan();

            $activeSpan = active_span();
            $spanLink = $activeSpan->links[0];
            $this->assertSame('12345678876543211234567887654321', $spanLink->traceId);
            $this->assertSame('8765432112345678', $spanLink->spanId);
            $this->assertSame('dd=t.dm:-0', $spanLink->traceState);
            $this->assertSame(['arg1' => 'value1'], $spanLink->attributes);
            $this->assertEquals(0, $spanLink->droppedAttributesCount);

            $otelSpan->end();
        });

        $this->assertCount(1, $traces[0]);
        $this->assertSame("[{\"trace_id\":\"12345678876543211234567887654321\",\"span_id\":\"8765432112345678\",\"trace_state\":\"dd=t.dm:-0\",\"attributes\":{\"arg1\":\"value1\"},\"dropped_attributes_count\":0}]", $traces[0][0]['meta']['_dd.span_links']);
    }

    public function testSpanLinksInteroperabilityBothTypes()
    {
        $sampledSpanContext = SpanContext::create(
            '12345678876543211234567887654321',
            '8765432112345678',
            TraceFlags::SAMPLED,
            new TraceState('dd=t.dm:-0')
        );

        $traces = $this->isolateTracer(function () use ($sampledSpanContext) {
            // Add 1 span link using the OTel API
            $otelSpan = self::getTracer()->spanBuilder("otel.span")
                ->addLink($sampledSpanContext, ['arg1' => 'value1'])
                ->startSpan();

            // Add 1 span link using the DD API
            $newSpanLink = new SpanLink();
            $newSpanLink->traceId = "ff0000000000051791e0000000000041";
            $newSpanLink->spanId = "ff00000000000517";
            active_span()->links[] = $newSpanLink;

            // Verify the span links from DD's POV
            $datadogSpanLinks = active_span()->links;
            $this->assertCount(2, $datadogSpanLinks);

            $this->assertSame('12345678876543211234567887654321', $datadogSpanLinks[0]->traceId);
            $this->assertSame('8765432112345678', $datadogSpanLinks[0]->spanId);
            $this->assertSame('dd=t.dm:-0', $datadogSpanLinks[0]->traceState);
            $this->assertSame(['arg1' => 'value1'], $datadogSpanLinks[0]->attributes);
            $this->assertEquals(0, $datadogSpanLinks[0]->droppedAttributesCount);

            $this->assertSame('ff0000000000051791e0000000000041', $datadogSpanLinks[1]->traceId);
            $this->assertSame('ff00000000000517', $datadogSpanLinks[1]->spanId);

            // Verify the span links from OTel's POV
            $otelSpanLinks = $otelSpan->toSpanData()->getLinks();

            $firstSpanLinkContext = $otelSpanLinks[0]->getSpanContext();
            $this->assertSame('12345678876543211234567887654321', $firstSpanLinkContext->getTraceId());
            $this->assertSame('8765432112345678', $firstSpanLinkContext->getSpanId());
            $this->assertSame('dd=t.dm:-0', (string) $firstSpanLinkContext->getTraceState());
            $this->assertSame(['arg1' => 'value1'], $otelSpanLinks[0]->getAttributes()->toArray());

            $secondSpanLinkContext = $otelSpanLinks[1]->getSpanContext();
            $this->assertSame('ff0000000000051791e0000000000041', $secondSpanLinkContext->getTraceId());
            $this->assertSame('ff00000000000517', $secondSpanLinkContext->getSpanId());


            $otelSpan->end();
        });

        $this->assertCount(1, $traces[0]);
        $this->assertSame("[{\"trace_id\":\"12345678876543211234567887654321\",\"span_id\":\"8765432112345678\",\"trace_state\":\"dd=t.dm:-0\",\"attributes\":{\"arg1\":\"value1\"},\"dropped_attributes_count\":0},{\"trace_id\":\"ff0000000000051791e0000000000041\",\"span_id\":\"ff00000000000517\"}]", $traces[0][0]['meta']['_dd.span_links']);
    }

    public function testSpanLinksInteroperabilityRemoval()
    {
        $sampledSpanContext = SpanContext::create(
            '12345678876543211234567887654321',
            '8765432112345678',
            TraceFlags::SAMPLED,
            new TraceState('dd=t.dm:-0')
        );

        $traces = $this->isolateTracer(function () use ($sampledSpanContext) {
            // Add 1 span link using the OTel API
            $otelSpan = self::getTracer()->spanBuilder("otel.span")
                ->addLink($sampledSpanContext, ['arg1' => 'value1'])
                ->startSpan();

            // Remove the span link using the DD API
            unset(active_span()->links[0]);

            // Add a span link using the DD API
            $newSpanLink = new SpanLink();
            $newSpanLink->traceId = "ff0000000000051791e0000000000041";
            $newSpanLink->spanId = "ff00000000000517";
            $newSpanLink->traceState = "dd=t.dm:-1";
            $newSpanLink->attributes = [
                'arg3' => 'value3',
                'arg4' => 'value4',
            ];
            active_span()->links[] = $newSpanLink;

            // Verify that there is only 1 span link from OTel's POV
            $otelSpanLinks = $otelSpan->toSpanData()->getLinks();
            $this->assertCount(1, $otelSpanLinks);
            $spanLinkContext = $otelSpanLinks[0]->getSpanContext();
            $this->assertSame('ff0000000000051791e0000000000041', $spanLinkContext->getTraceId());
            $this->assertSame('ff00000000000517', $spanLinkContext->getSpanId());
            $this->assertSame('dd=t.dm:-1', (string) $spanLinkContext->getTraceState());
            $this->assertSame(['arg3' => 'value3', 'arg4' => 'value4'], $otelSpanLinks[0]->getAttributes()->toArray());

            $otelSpan->end();
        });

        $this->assertCount(1, $traces[0]);
    }

    public function testSpanLinksInteroperabilityRemovalDuplicates()
    {
        $traces = $this->isolateTracer(function () {
            $context1 = SpanContext::create('00000000000000000000000000000001', '0000000000000001');
            $context2 = SpanContext::create('00000000000000000000000000000002', '0000000000000002');
            $context3 = SpanContext::create('00000000000000000000000000000003', '0000000000000003');

            // Otel -> [Link(T1, S1), Link(T2, S2), Link(T2, S2), Link(T3, S3)]
            $otelSpan = self::getTracer()->spanBuilder("otel.span")
                ->addLink($context1)
                ->addLink($context2)
                ->addLink($context2) // Duplicate
                ->addLink($context3)
                ->startSpan();
            $initialOtelSpanLinks = $otelSpan->toSpanData()->getLinks();

            // Modify to [Link(T1, S1), Link(T2, S2), Link(T4, S4)]
            unset(active_span()->links[3]); // Remove Link(T3, S3)
            unset(active_span()->links[1]); // Remove Link(T2, S2)
            $newSpanLink = new SpanLink();
            $newSpanLink->traceId = "00000000000000000000000000000004";
            $newSpanLink->spanId = "0000000000000004";
            active_span()->links[] = $newSpanLink; // Add Link(T4, S4)

            // Verify the spans links from OTel's POV
            $otelSpanLinks = $otelSpan->toSpanData()->getLinks();
            $this->assertCount(3, $otelSpanLinks);

            // Verify Link(T1, S1) + Instance
            $spanLinkContext = $otelSpanLinks[0]->getSpanContext();
            $this->assertSame('00000000000000000000000000000001', $spanLinkContext->getTraceId());
            $this->assertSame('0000000000000001', $spanLinkContext->getSpanId());
            $this->assertSame($initialOtelSpanLinks[0], $otelSpanLinks[0]);

            // Verify Link(T2, S2) + Instance
            $spanLinkContext = $otelSpanLinks[1]->getSpanContext();
            $this->assertSame('00000000000000000000000000000002', $spanLinkContext->getTraceId());
            $this->assertSame('0000000000000002', $spanLinkContext->getSpanId());
            $this->assertSame($initialOtelSpanLinks[2], $otelSpanLinks[1]);

            // Verify Link(T4, S4)
            $spanLinkContext = $otelSpanLinks[2]->getSpanContext();
            $this->assertSame('00000000000000000000000000000004', $spanLinkContext->getTraceId());
            $this->assertSame('0000000000000004', $spanLinkContext->getSpanId());
        });
    }

    public function testSpanLinksInteroperabilityAddDuplicates()
    {
        $traces = $this->isolateTracer(function () {
            $context1 = SpanContext::create('00000000000000000000000000000001', '0000000000000001');
            $context2 = SpanContext::create('00000000000000000000000000000002', '0000000000000002');

            $otelSpan = self::getTracer()->spanBuilder("otel.span")
                ->addLink($context1)
                ->addLink($context2)
                ->startSpan();

            $spanLink2 = active_span()->links[1];
            active_span()->links[] = $spanLink2; // Duplicate, same instance

            $otelSpanLinks = $otelSpan->toSpanData()->getLinks();

            $this->assertCount(3, $otelSpanLinks);
            // Verify that the duplicate is the same instance
            $this->assertSame($otelSpanLinks[1], $otelSpanLinks[2]);

            // Create a new span link with the same trace id and span id
            $newSpanLink = new SpanLink();
            $newSpanLink->traceId = $spanLink2->traceId;
            $newSpanLink->spanId = $spanLink2->spanId;
            active_span()->links[] = $newSpanLink; // Duplicate, but different instance

            $otelSpanLinks = $otelSpan->toSpanData()->getLinks();
            $this->assertCount(4, $otelSpanLinks);
            // Verify that the duplicate is not the same instance
            $this->assertNotSame($otelSpanLinks[1], $otelSpanLinks[3]);
        });
    }

    public function testSpanEventsInteroperabilityFromDatadogSpan()
    {
        $traces = $this->isolateTracer(function () {
            $span = start_span();
            $span->name = "dd.span";

            $spanEvent = new SpanEvent(
                "event-name", 
                [ 
                    'arg1' => 'value1', 
                    'int_array' => [3, 4], 
                    'string_array' => ["5", "6"]
                ], 
                1720037568765201300
            );
            $span->events[] = $spanEvent;

            /** @var en $OTelSpan */
            $otelSpan = Span::getCurrent();
            $otelSpanEvent = $otelSpan->toSpanData()->getEvents()[0];

            $this->assertSame('event-name', $otelSpanEvent->getName());
            $this->assertSame([ 
                'arg1' => 'value1', 
                'int_array' => [3, 4], 
                'string_array' => ["5", "6"]
            ], $otelSpanEvent->getAttributes()->toArray());
            $this->assertSame(1720037568765201300, (int)$otelSpanEvent->getEpochNanos());

            close_span();
        });

        $this->assertCount(1, $traces[0]);
        $this->assertSame("[{\"name\":\"event-name\",\"time_unix_nano\":1720037568765201300,\"attributes\":{\"arg1\":\"value1\",\"int_array\":[3,4],\"string_array\":[\"5\",\"6\"]}}]", $traces[0][0]['meta']['events']);
    }

    public function testSpanEventsInteroperabilityFromOpenTelemetrySpan()
    {
        $traces = $this->isolateTracer(function () {
            $otelSpan = self::getTracer()->spanBuilder("otel.span")
                ->startSpan();
            $otelSpan->addEvent(
                "event-name", 
                [ 
                    'arg1' => 'value1', 
                    'int_array' => [3, 4], 
                    'string_array' => ["5", "6"]
                ], 
                1720037568765201300
            );

            $activeSpan = active_span();
            $spanEvent = $activeSpan->events[0];
            $this->assertSame("event-name", $spanEvent->name);
            $this->assertSame([ 
                'arg1' => 'value1', 
                'int_array' => [3, 4], 
                'string_array' => ["5", "6"]
            ], $spanEvent->attributes);
            $this->assertSame(1720037568765201300, (int)$spanEvent->timestamp);

            $otelSpan->end();
        });

        $this->assertCount(1, $traces[0]);
        $this->assertSame("[{\"name\":\"event-name\",\"time_unix_nano\":1720037568765201300,\"attributes\":{\"arg1\":\"value1\",\"int_array\":[3,4],\"string_array\":[\"5\",\"6\"]}}]", $traces[0][0]['meta']['events']);
    }

    public function testOtelRecordExceptionAttributesSerialization()
    {
        $lastException = new \Exception("woof3");

        $traces = $this->isolateTracer(function () use ($lastException)  {
            $otelSpan = self::getTracer()->spanBuilder("operation")
                ->recordException(new \Exception("woof1"), [
                    "string_val" => "value",
                    "exception.stacktrace" => "stacktrace1"
                ])
                ->startSpan();

            $otelSpan->addEvent("non_exception_event", ["exception.stacktrace" => "non-error"]);
            $otelSpan->recordException($lastException, ["exception.message" => "message override"]);

            $otelSpan->end();
        });

        $events = json_decode($traces[0][0]['meta']['events'], true);
        $this->assertCount(3, $events);
    
        $event1 = $events[0];
        $this->assertSame('value', $event1['attributes']['string_val']);
        $this->assertSame('woof1', $event1['attributes']['exception.message']);
        $this->assertSame('stacktrace1', $event1['attributes']['exception.stacktrace']);
    
        $event2 = $events[1];
        $this->assertSame('non-error', $event2['attributes']['exception.stacktrace']);
    
        $event3 = $events[2];
        $this->assertSame('message override', $event3['attributes']['exception.message']);

        $this->assertSame(\DDTrace\get_sanitized_exception_trace($lastException), $traces[0][0]['meta']['error.stack']);

        $this->assertArrayNotHasKey('error.message', $traces[0][0]['meta']);
        $this->assertArrayNotHasKey('error.type', $traces[0][0]['meta']);
        $this->assertArrayNotHasKey('error', $traces[0][0]);
    }

    public function testExceptionSpanEvents()
    {
        $traces = $this->isolateTracer(function () {
            $span = start_span();
            $span->name = "dd.span";

            $spanEvent = new ExceptionSpanEvent(
                new \Exception("Test exception message"),
                [ 
                    'arg1' => 'value1', 
                    'exception.stacktrace' => 'Stacktrace Override'
                ]
            );

            $span->events[] = $spanEvent;

            /** @var Span $otelSpan */
            $otelSpan = Span::getCurrent();
            $otelSpanEvent = $otelSpan->toSpanData()->getEvents()[0];

            $this->assertSame('exception', $otelSpanEvent->getName());
            $this->assertSame([ 
                'exception.message' => 'Test exception message',
                'exception.type' => 'Exception',
                'exception.stacktrace' => 'Stacktrace Override',
                'arg1' => 'value1'
            ], $otelSpanEvent->getAttributes()->toArray());

            close_span();
        });
        $event = json_decode($traces[0][0]['meta']['events'], true)[0];

        $this->assertSame('Test exception message', $event['attributes']['exception.message']);
        $this->assertSame('Exception', $event['attributes']['exception.type']);
        $this->assertSame('Stacktrace Override', $event['attributes']['exception.stacktrace']);
        $this->assertSame('value1', $event['attributes']['arg1']);
    }

    public function testBaggageApiInteroperability()
    {
        // //1. OpenTelemetry Baggage is Propagated to Datadog
        $otelToDatadog = $this->isolateTracer(function () {
            $tracer = (new TracerProvider())->getTracer('OpenTelemetry.TestTracer');
    
            $parentSpan = $tracer->spanBuilder('parent')
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->startSpan();
            $parentSpanScope = $parentSpan->activate();

            $baggage = Baggage::getBuilder()
                ->set('otel_key', 'otel_value')
                ->build();
            $baggageScope = $baggage->storeInContext(Context::getCurrent())->activate();

            $span = start_span();
            $span->name = 'dd.span';

            $this->assertSame("otel_value", $span->baggage["otel_key"]);

            $baggageScope->detach();
            $parentSpanScope->detach();
            $parentSpan->end();
            close_span();
        });

        //2. Datadog Baggage is Accessible from OpenTelemetry
        $datadogToOtel = $this->isolateTracer(function () {
            $span = start_span();
            $span->name = "dd.span";
            $span->baggage["dd_key"] = "dd_value";

            $tracer = (new TracerProvider())->getTracer('OpenTelemetry.TestTracer');
            $baggage = Baggage::getCurrent();

            $this->assertSame('dd_value', $baggage->getValue('dd_key'));
            close_span();
        });

        // 3. Conflict Handling Between OpenTelemetry and Datadog Baggage Keys
        $datadogAndOtelSharingKeys = $this->isolateTracer(function () {
            $tracer = (new TracerProvider())->getTracer('OpenTelemetry.TestTracer');
    
            $parentSpan = $tracer->spanBuilder('parent')
                ->setSpanKind(SpanKind::KIND_SERVER)
                ->startSpan();
            $parentSpanScope = $parentSpan->activate();

            $baggage = Baggage::getBuilder()
                ->set('otel_key', 'otel_value')
                ->set('shared_key', 'first_value')
                ->build();
            $baggageScope = $baggage->storeInContext(Context::getCurrent())->activate();
    
            $span = start_span();
            $span->name = 'dd.span';
            $span->baggage['dd_key'] = 'dd_value';
            $span->baggage['shared_key'] = 'second_value';
    
            $this->assertSame('otel_value', $span->baggage['otel_key']);
            $this->assertSame('dd_value', $span->baggage['dd_key']);
            $this->assertSame('second_value', $span->baggage['shared_key']);

            close_span();
            $baggageScope->detach();
            $parentSpan->end();
            $parentSpanScope->detach();
        });

        // 4. OpenTelemetry Baggage Removal Reflects in Datadog
        $otelDeletedOnDatadog = $this->isolateTracer(function () {
            $tracer = (new TracerProvider())->getTracer('OpenTelemetry.TestTracer');
    
            $baggage = Baggage::getBuilder()
                ->set('otel_key', 'otel_value')
                ->set('to_be_deleted', 'should_be_deleted')
                ->build();
            $baggageScope = $baggage->storeInContext(Context::getCurrent())->activate();
    
            $parentSpan = $tracer->spanBuilder('parent')
                ->setSpanKind(SpanKind::KIND_SERVER)
                ->startSpan();
            $parentSpanScope = $parentSpan->activate();

            $baggage = Baggage::getCurrent();
            $baggage->toBuilder()->remove('to_be_deleted')->build()->activate();

            $span = start_span();
            $span->name = 'dd.span';

            $this->assertArrayNotHasKey("to_be_deleted", $span->baggage); 
            $this->assertSame('otel_value', $span->baggage['otel_key']); 

            close_span();
            $baggageScope->detach();
            $parentSpan->end();
            $parentSpanScope->detach();
        });
    }

    public function testEndToEndBaggage()
    {
        // 1. Injected baggage is kept in the context
        $otelContextExample = $this->isolateTracer(function () {
            $baggage = Baggage::getBuilder()
                ->set('user_id', '12345')
                ->set('session', 'xyz')
                ->build();
            $context = Context::getCurrent()->withContextValue($baggage);
            
            // Step 2: Inject into Headers
            $carrier = [];
            BaggagePropagator::getInstance()->inject($carrier, null, $context);
            
            // Step 3: Extract into a New Context
            $newContext = BaggagePropagator::getInstance()->extract($carrier);
            
            // Step 4: Validate the Extracted Baggage Items
            $extractedBaggage = Baggage::fromContext($newContext);
            
            $this->assertSame($extractedBaggage->getValue('user_id'), '12345');
            $this->assertSame($extractedBaggage->getValue('session'), 'xyz');
        });
    }

}
