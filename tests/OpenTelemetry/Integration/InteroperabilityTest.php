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
    // TODO: Change things to use SpanAssertionTrait's capabilities instead

    // Source: https://magp.ie/2015/09/30/convert-large-integer-to-hexadecimal-without-php-math-extension/
    private static function largeBaseConvert($numString, $fromBase, $toBase)
    {
        $chars = "0123456789abcdefghijklmnopqrstuvwxyz";
        $toString = substr($chars, 0, $toBase);

        $length = strlen($numString);
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $number[$i] = strpos($chars, $numString[$i]);
        }
        do {
            $divide = 0;
            $newLen = 0;
            for ($i = 0; $i < $length; $i++) {
                $divide = $divide * $fromBase + $number[$i];
                if ($divide >= $toBase) {
                    $number[$newLen++] = (int)($divide / $toBase);
                    $divide = $divide % $toBase;
                } elseif ($newLen > 0) {
                    $number[$newLen++] = 0;
                }
            }
            $length = $newLen;
            $result = $toString[$divide] . $result;
        } while ($newLen != 0);

        return $result;
    }

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
            $this->assertSame(str_pad(strtolower(self::largeBaseConvert($ddSpan->id, 10, 16)), 16, '0', STR_PAD_LEFT), $currentSpan->getContext()->getSpanId());
            $this->assertSame(str_pad(strtolower(self::largeBaseConvert(trace_id(), 10, 16)), 32, '0', STR_PAD_LEFT), $currentSpan->getContext()->getTraceId());

            // Get current scope
            $currentScope = Context::storage()->scope();
            $this->assertNotNull($currentScope);
            $currentScope->detach();
            $currentSpan = Span::getCurrent();

            // Shouldn't have changed
            $this->assertNotNull($currentSpan);
            $this->assertSame($ddSpan, $currentSpan->getDDSpan());
            $this->assertSame(str_pad(strtolower(self::largeBaseConvert($ddSpan->id, 10, 16)), 16, '0', STR_PAD_LEFT), $currentSpan->getContext()->getSpanId());
            $this->assertSame(str_pad(strtolower(self::largeBaseConvert(trace_id(), 10, 16)), 32, '0', STR_PAD_LEFT), $currentSpan->getContext()->getTraceId());

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
            $spanId = str_pad(strtolower(self::largeBaseConvert($ddSpan->id, 10, 16)), 16, '0', STR_PAD_LEFT);
            $parentId = str_pad(strtolower(self::largeBaseConvert($ddSpan->parent->id, 10, 16)), 16, '0', STR_PAD_LEFT);
            $this->assertSame($span->getContext()->getSpanId(), $parentId);

            $currentSpan = Span::getCurrent();
            $this->assertSame($span->getContext()->getSpanId(), $currentSpan->getParentContext()->getSpanId());
            $currentSpan->setAttributes([
                'foo' => 'bar',
            ]);

            $traceId = str_pad(strtolower(self::largeBaseConvert(trace_id(), 10, 16)), 32, '0', STR_PAD_LEFT);
            $this->assertSame($traceId, $currentSpan->getContext()->getTraceId());
            $spanId = str_pad(strtolower(self::largeBaseConvert($ddSpan->id, 10, 16)), 16, '0', STR_PAD_LEFT);
            $this->assertSame($spanId, $currentSpan->getContext()->getSpanId());

            close_span(); // Note that we don't detach the scope
            $scope->detach();
            $span->end();
        });

        $spans = $traces[0];
        $this->assertCount(2, $spans);

        list($parent, $child) = $spans;
        $this->assertSame('test.span', $parent['name']);
        $this->assertSame('other.span', $child['name']);
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

            $this->assertNotEmpty($currentSpan);
            $this->assertSame($currentSpan->getDDSpan(), $ddSpan); // The OTel span wasn't activated, so the current span is the DDTrace span

            $ddOTelSpan = $currentSpan;
            $OTelScope = $OTelSpan->activate();

            /** @var \OpenTelemetry\SDK\Trace\Span $currentSpan */
            $currentSpan = Span::getCurrent();

            $this->assertNotEmpty($currentSpan);
            $this->assertSame($OTelSpan, $currentSpan);
            $this->assertSame($ddOTelSpan->getContext()->getSpanId(), $currentSpan->getParentContext()->getSpanId());

            $OTelScope->detach();
            $OTelSpan->end();
            $ddOTelSpan->end();
        });

        $spans = $traces[0];
        $this->assertCount(2, $spans);

        list($ddSpan, $OTelSpan) = $spans;
        $this->assertSame('dd.span', $ddSpan['name']);
        $this->assertSame('otel.span', $OTelSpan['name']);
        $this->assertSame($ddSpan['span_id'], $OTelSpan['parent_id']);
        $this->assertSame($ddSpan['trace_id'], $OTelSpan['trace_id']);
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

        list($OTelSpan, $ddSpan) = $spans;
        $this->assertSame('otel.span', $OTelSpan['name']);
        $this->assertSame('dd.span', $ddSpan['name']);
        $this->assertSame($OTelSpan['span_id'], $ddSpan['parent_id']);
        $this->assertSame($OTelSpan['trace_id'], $ddSpan['trace_id']);

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
            $this->assertSame('otel.parent.span', $activeSpan->name);
            $this->assertSame(str_pad(strtolower(self::largeBaseConvert($activeSpan->id, 10, 16)), 16, '0', STR_PAD_LEFT), $OTelParentSpan->getContext()->getSpanId());

            $ddChildSpan = start_span();
            $ddChildSpan->name = "dd.child.span";

            $ddChildSpanAsOTel = Span::getCurrent();

            $this->assertNotNull($ddChildSpanAsOTel);
            $this->assertSame($ddChildSpan, $ddChildSpanAsOTel->getDDSpan());

            $OTelGrandChildSpan = $tracer->spanBuilder("otel.grandchild.span")->startSpan();
            $OTelGrandChildScope = $OTelGrandChildSpan->activate();

            $activeSpan = active_span();
            $this->assertNotNull($activeSpan);
            $this->assertSame('otel.grandchild.span', $activeSpan->name);
            $this->assertSame(str_pad(strtolower(self::largeBaseConvert($activeSpan->id, 10, 16)), 16, '0', STR_PAD_LEFT), $OTelGrandChildSpan->getContext()->getSpanId());

            $OTelGrandChildScope->detach();
            $OTelGrandChildSpan->end();
            $ddChildSpanAsOTel->end();
            $OTelParentScope->detach();
            $OTelParentSpan->end();
        });

        $spans = $traces[0];
        $this->assertCount(3, $spans);

        list($OTelParentSpan, $ddChildSpan, $OTelGrandChildSpan) = $spans;
        $this->assertSame('otel.parent.span', $OTelParentSpan['name']);
        $this->assertSame('dd.child.span', $ddChildSpan['name']);
        $this->assertSame('otel.grandchild.span', $OTelGrandChildSpan['name']);

        $this->assertSame($OTelParentSpan['span_id'], $ddChildSpan['parent_id']);
        $this->assertSame($OTelParentSpan['trace_id'], $ddChildSpan['trace_id']);

        $this->assertSame($ddChildSpan['span_id'], $OTelGrandChildSpan['parent_id']);
        $this->assertSame($OTelParentSpan['trace_id'], $OTelGrandChildSpan['trace_id']);
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

        $spans = $traces[0];
        $this->assertCount(6, $spans);

        list($OTelRootSpan, $OTelChildSpan, $DDChildSpan, $DDRootSpan, $DDRootOTelSpan, $DDRootChildSpan) = $spans;
        $this->assertSame('otel.root.span', $OTelRootSpan['name']);
        $this->assertSame('otel.child.span', $OTelChildSpan['name']);
        $this->assertSame('dd.child.span', $DDChildSpan['name']);

        $this->assertSame('dd.root.span', $DDRootSpan['name']);
        $this->assertSame('dd.root.otel.span', $DDRootOTelSpan['name']);
        $this->assertSame('dd.root.child.span', $DDRootChildSpan['name']);

        $this->assertSame($OTelRootSpan['trace_id'], $OTelChildSpan['trace_id']);
        $this->assertSame($OTelRootSpan['span_id'], $OTelChildSpan['parent_id']);

        $this->assertSame($OTelRootSpan['trace_id'], $DDChildSpan['trace_id']);
        $this->assertSame($OTelChildSpan['span_id'], $DDChildSpan['parent_id']);

        $this->assertNotSame($OTelRootSpan['trace_id'], $DDRootSpan['trace_id']);
        $this->assertArrayNotHasKey('parent_id', $DDRootSpan['meta']);

        $this->assertSame($DDRootSpan['trace_id'], $DDRootOTelSpan['trace_id']);
        $this->assertSame($DDRootSpan['span_id'], $DDRootOTelSpan['parent_id']);

        $this->assertSame($DDRootSpan['trace_id'], $DDRootChildSpan['trace_id']);
        $this->assertSame($DDRootOTelSpan['span_id'], $DDRootChildSpan['parent_id']);
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

        $spans = $traces[0];
        $this->assertCount(6, $spans);

        list($OTelRootSpan, $OTelChildSpan, $DDChildSpan, $DDRootSpan, $DDRootOTelSpan, $DDRootChildSpan) = $spans;
        $this->assertSame('otel.root.span', $OTelRootSpan['name']);
        $this->assertSame('otel.child.span', $OTelChildSpan['name']);
        $this->assertSame('dd.child.span', $DDChildSpan['name']);

        $this->assertSame('dd.root.span', $DDRootSpan['name']);
        $this->assertSame('dd.root.otel.span', $DDRootOTelSpan['name']);
        $this->assertSame('dd.root.child.span', $DDRootChildSpan['name']);

        $this->assertSame($OTelRootSpan['trace_id'], $OTelChildSpan['trace_id']);
        $this->assertSame($OTelRootSpan['span_id'], $OTelChildSpan['parent_id']);

        $this->assertSame($OTelRootSpan['trace_id'], $DDChildSpan['trace_id']);
        $this->assertSame($OTelChildSpan['span_id'], $DDChildSpan['parent_id']);

        $this->assertNotSame($OTelRootSpan['trace_id'], $DDRootSpan['trace_id']);
        $this->assertArrayNotHasKey('parent_id', $DDRootSpan['meta']);

        $this->assertSame($DDRootSpan['trace_id'], $DDRootOTelSpan['trace_id']);
        $this->assertSame($DDRootSpan['span_id'], $DDRootOTelSpan['parent_id']);

        $this->assertSame($DDRootSpan['trace_id'], $DDRootChildSpan['trace_id']);
        $this->assertSame($DDRootOTelSpan['span_id'], $DDRootChildSpan['parent_id']);
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

        $spans = $traces[0];
        $this->assertCount(6, $spans);

        list($OTelRootSpan, $DDRootSpan, $OTelChildSpan, $DDChildSpan, $OTelGrandChildSpan, $DDGrandChildSpan) = $spans;

        $this->assertSame('otel.root.span', $OTelRootSpan['name']);
        $this->assertSame('dd.root.span', $DDRootSpan['name']);
        $this->assertSame('otel.child.span', $OTelChildSpan['name']);
        $this->assertSame('dd.child.span', $DDChildSpan['name']);
        $this->assertSame('otel.grandchild.span', $OTelGrandChildSpan['name']);
        $this->assertSame('dd.grandchild.span', $DDGrandChildSpan['name']);

        $this->assertSame($OTelRootSpan['trace_id'], $OTelChildSpan['trace_id']);
        $this->assertSame($OTelRootSpan['span_id'], $OTelChildSpan['parent_id']);

        $this->assertSame($OTelRootSpan['trace_id'], $DDChildSpan['trace_id']);
        $this->assertSame($OTelChildSpan['span_id'], $DDChildSpan['parent_id']);

        $this->assertNotSame($OTelRootSpan['trace_id'], $DDRootSpan['trace_id']);
        $this->assertArrayNotHasKey('parent_id', $DDRootSpan['meta']);

        $this->assertSame($DDRootSpan['trace_id'], $OTelGrandChildSpan['trace_id']);
        $this->assertSame($DDRootSpan['span_id'], $OTelGrandChildSpan['parent_id']);

        $this->assertSame($DDRootSpan['trace_id'], $DDGrandChildSpan['trace_id']);
        $this->assertSame($OTelGrandChildSpan['span_id'], $DDGrandChildSpan['parent_id']);
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
            SpanAssertion::build('otel.trace1', 'datadog/dd-trace-tests', 'cli', 'otel.trace1')
                ->withExactTags([
                    'foo1' => 'bar1',
                ])
                ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                ->withChildren(
                    SpanAssertion::build('otel.child1', 'my.service', 'cli', 'otel.child1')
                        ->withExactTags([
                            'foo2' => 'bar2',
                        ])
                        ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                        ->withChildren(
                            SpanAssertion::build('otel.child3', 'datadog/dd-trace-tests', 'cli', 'my.resource')
                                ->withExactTags([
                                    'foo3' => 'bar3',
                                ])
                                ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                        )
                ),
            SpanAssertion::exists('otel.trace2', 'otel.trace2', null, 'datadog/dd-trace-tests')
                ->withChildren(
                    SpanAssertion::exists('dd.child2', 'dd.child2', null, 'datadog/dd-trace-tests')
                ),
            SpanAssertion::build('dd.trace1', 'phpunit', 'cli', 'dd.trace1')
                ->withExactTags([
                    'foo1' => 'bar1',
                ])
                ->withChildren(
                    SpanAssertion::build('dd.child1', 'phpunit', 'cli', 'dd.child1')
                ),
            SpanAssertion::build('dd.trace2', 'datadog/dd-trace-tests', 'cli', 'dd.trace2')
                ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                ->withChildren(
                    SpanAssertion::build('otel.child2', 'datadog/dd-trace-tests', 'cli', 'otel.child2')
                        ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                        ->withChildren(
                            SpanAssertion::build('otel.child4', 'datadog/dd-trace-tests', 'cli', 'otel.child4')
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
            $this->assertSame('dd=t.tid:ff00000000000517;t.dm:-1', $carrier[TraceContextPropagator::TRACESTATE]); // ff00000000000517 is the high 64-bit part of the 128-bit trace id
        });

        $this->assertSame('10511401530282737729', $traces[0][0]['trace_id']);
        $this->assertSame('18374692078461386817', $traces[0][0]['parent_id']);

        $this->assertFlameGraph($traces, [
            SpanAssertion::build('otel.root.span', 'datadog/dd-trace-tests', 'cli', 'otel.root.span')
                ->withExactTags([
                    '_dd.p.tid' => 'ff00000000000517'
                ])
                ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                ->withChildren(
                    SpanAssertion::build('dd.child.span', 'datadog/dd-trace-tests', 'cli', 'dd.child.span')
                        ->withExactTags([
                            '_dd.p.tid' => 'ff00000000000517'
                        ])
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
            SpanAssertion::build('otel.root.span', 'datadog/dd-trace-tests', 'cli', 'otel.root.span')
                ->withExactTags([
                    '_dd.p.tid' => 'ff00000000000517'
                ])
                ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                ->withChildren(
                    SpanAssertion::build('dd.child.span', 'datadog/dd-trace-tests', 'cli', 'dd.child.span')
                        ->withExactTags([
                            '_dd.p.tid' => 'ff00000000000517'
                        ])
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
            SpanAssertion::build('otel.root.span', 'datadog/dd-trace-tests', 'cli', 'otel.root.span')
                ->withExactTags([
                    '_dd.p.tid' => 'ff00000000000517'
                ])
                ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                ->withChildren(
                    SpanAssertion::build('dd.child.span', 'datadog/dd-trace-tests', 'cli', 'dd.child.span')
                        ->withExactTags([
                            '_dd.p.tid' => 'ff00000000000517'
                        ])
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
            SpanAssertion::build('parent', 'datadog/dd-trace-tests', 'cli', 'parent')
                ->withExactTags([
                    Tag::SPAN_KIND => Tag::SPAN_KIND_VALUE_SERVER,
                    'http.method' => 'GET',
                    'http.uri' => '/parent'
                ])
                ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                ->withChildren([
                    SpanAssertion::build('child', 'datadog/dd-trace-tests', 'cli', 'child')
                        ->withExactTags([
                            'user.id' => '1',
                        ])
                        ->withExistingTagsNames(InteroperabilityTest::commonTagsList())
                ])
        ]);
    }

    public function testFiberInteroperabilityStackSwitch()
    {
        // See tests/ext/fiber_stack_switch.phpt

        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            $this->markTestSkipped('Fibers are only supported in PHP 8.1+');
        }

        $traces = $this->isolateTracer(function () {
            $tracer = (new TracerProvider())->getTracer('OpenTelemetry.TestTracer');

            $parentSpan = $tracer->spanBuilder('parent')
                ->setSpanKind(SpanKind::KIND_SERVER)
                ->startSpan();
            $parentSpanScope = $parentSpan->activate();

            $otherFiberFn = function () use ($tracer) {
                $currentSpan = Span::getCurrent();
                $this->assertSame('otherFiber', $currentSpan->getName());

                $span = start_span();
                $span->name = 'dd.otherFiber';

                $currentSpan = Span::getCurrent();
                $this->assertSame('dd.otherFiber', $currentSpan->getName());

                Fiber::suspend();

                $currentSpan = Span::getCurrent();
                $this->assertSame('dd.otherFiber', $currentSpan->getName());

                close_span();

                $currentSpan = Span::getCurrent();
                $this->assertSame('otherFiber', $currentSpan->getName());

                throw new \Exception("ex");
            };

            $otherFiber = null;
            $inFiberFn = function () use ($parentSpan, $otherFiberFn, &$otherFiber, $tracer) {
                $currentSpan = Span::getCurrent();
                $this->assertSame('inFiber', $currentSpan->getName());

                $inFiberOTelSpan = $tracer->spanBuilder('otel.inFiber')->startSpan();
                $inFiberOTelScope = $inFiberOTelSpan->activate();

                $otherFiber = new Fiber($otherFiberFn(...));
                $otherFiber->start();

                $currentSpan = Span::getCurrent();
                $this->assertSame('otel.inFiber', $currentSpan->getName());

                Fiber::suspend(123);

                $currentSpan = Span::getCurrent();
                $this->assertSame('otel.inFiber', $currentSpan->getName());

                $inFiberOTelScope->detach();
                $inFiberOTelSpan->end();

                $currentSpan = Span::getCurrent();
                $this->assertSame('inFiber', $currentSpan->getName());
            };

            \DDTrace\trace_method('Fiber', 'start', function (SpanData $span) {
                 $span->name = 'fiber.start';
            });

            \DDTrace\trace_method('Fiber', 'suspend', [
                'posthook' => function (SpanData $span) {
                    $span->name = 'fiber.suspend';
                },
                'recurse' => true
            ]);

            \DDTrace\trace_method('Fiber', 'resume', function (SpanData $span) {
                $span->name = 'fiber.resume';
            });

            \DDTrace\install_hook($inFiberFn, function (HookData $hook) {
                $span = $hook->span();
                $span->name = 'inFiber';
            });

            \DDTrace\install_hook($otherFiberFn, function (HookData $hook) {
                $span = $hook->span();
                $span->name = 'otherFiber';
            });

            $currentSpan = Span::getCurrent();
            $this->assertSame('parent', $currentSpan->getName());

            $fiber = new Fiber($inFiberFn(...));
            $fiber->start();

            $currentSpan = Span::getCurrent();
            $this->assertSame('parent', $currentSpan->getName());

            $parentSpan->setAttribute('http.method', 'GET');

            $fiber->resume();

            $currentSpan = Span::getCurrent();
            $this->assertSame('parent', $currentSpan->getName());

            $parentSpan->setAttribute('http.uri', '/parent');

            try {
                $otherFiber->resume();
            } catch (\Exception) {
            }

            $currentSpan = Span::getCurrent();
            $this->assertSame('parent', $currentSpan->getName());

            $parentSpanScope->detach();
            $parentSpan->end();
        });

        $this->assertFlameGraph($traces, [
            SpanAssertion::exists('parent')->withChildren([
                    SpanAssertion::exists('fiber.start')->withChildren([
                            SpanAssertion::exists('inFiber')->withChildren([
                                    SpanAssertion::exists('otel.inFiber')->withChildren([
                                            SpanAssertion::exists('otherFiber')->withChildren([
                                                SpanAssertion::exists('dd.otherFiber')->withChildren([
                                                    SpanAssertion::exists('fiber.suspend')
                                                ])
                                            ])->withExistingTagsNames([
                                                Tag::ERROR_TYPE, Tag::ERROR_STACK, Tag::ERROR_MSG
                                            ]),
                                            SpanAssertion::exists('fiber.suspend')
                                        ])
                                ])
                        ]),
                    SpanAssertion::exists('fiber.resume'),
                    SpanAssertion::exists('fiber.resume')->withExistingTagsNames([
                        Tag::ERROR_TYPE, Tag::ERROR_STACK, Tag::ERROR_MSG
                    ])
                ])
        ]);
    }
}
