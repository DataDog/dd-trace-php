<?php

namespace DDTrace\Tests\OpenTelemetry\Integration;

use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tests\Common\TracerTestTrait;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextValidator;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TraceState;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\TracerProvider;
use function DDTrace\active_span;
use function DDTrace\close_span;
use function DDTrace\close_spans_until;
use function DDTrace\generate_distributed_tracing_headers;
use function DDTrace\start_span;
use function DDTrace\trace_id;

final class InteroperabilityTest extends BaseTestCase
{
    use TracerTestTrait;

    // TODO: Implement AttributesBuilder and add a method to retrieve the attributeCountLimit

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
        self::putEnv("DD_TRACE_DEBUG=");
        parent::ddTearDown();
        \dd_trace_serialize_closed_spans();
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
            print("DD Span Id: " . str_pad(strtolower(self::largeBaseConvert($ddSpan->id, 10, 16)), 16, '0', STR_PAD_LEFT) . "\n");

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

            print("----\n");
            close_span();
            print("----\n");
            $currentSpan = Span::getCurrent();
            print("Current Span Id: " . $currentSpan->getContext()->getSpanId() . PHP_EOL);
            $this->assertSame(SpanContextValidator::INVALID_SPAN, $currentSpan->getContext()->getSpanId());
            $this->assertSame(SpanContextValidator::INVALID_TRACE, $currentSpan->getContext()->getTraceId());
        });

        fwrite(STDERR, json_encode($traces, JSON_PRETTY_PRINT) . PHP_EOL);
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

            print("~~1~~\n");
            $currentSpan = Span::getCurrent();
            print("~~1~~\n");

            $this->assertNotNull($currentSpan);
            $this->assertSame(SpanContextValidator::INVALID_TRACE, $currentSpan->getContext()->getTraceId());
            $this->assertSame(SpanContextValidator::INVALID_SPAN, $currentSpan->getContext()->getSpanId());
            $this->assertSame(SpanContext::getInvalid(), $currentSpan->getContext());

            $scope = $span->activate();
            print("~~2~~\n");
            $currentSpan = Span::getCurrent();
            print("~~2~~\n");

            $this->assertNotNull($currentSpan);
            $this->assertSame($span, $currentSpan);
            print("Span ID: " . str_pad(strtolower(self::largeBaseConvert($span->getContext()->getSpanId(), 10, 16)), 16, '0', STR_PAD_LEFT) . "\n");

            $ddSpan = \DDTrace\start_span();
            $ddSpan->name = "other.span";
            $spanId = str_pad(strtolower(self::largeBaseConvert($ddSpan->id, 10, 16)), 16, '0', STR_PAD_LEFT);
            print("Created DDSpan $spanId\n");
            $parentId = str_pad(strtolower(self::largeBaseConvert($ddSpan->parent->id, 10, 16)), 16, '0', STR_PAD_LEFT);
            $this->assertSame($span->getContext()->getSpanId(), $parentId);

            print("~~3~~\n");
            $currentSpan = Span::getCurrent();
            $this->assertSame($span->getContext()->getSpanId(), $currentSpan->getParentContext()->getSpanId());
            print("~~3~~\n");
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
        fwrite(STDERR, json_encode($spans, JSON_PRETTY_PRINT) . PHP_EOL);
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
            print("Span1 ID: " . str_pad(strtolower(self::largeBaseConvert($span1->id, 10, 16)), 16, '0', STR_PAD_LEFT) . "\n");
            $span2 = start_span();
            $span2->name = "dd.span2";
            print("Span2 ID: " . str_pad(strtolower(self::largeBaseConvert($span2->id, 10, 16)), 16, '0', STR_PAD_LEFT) . "\n");
            $span3 = start_span();
            $span3->name = "dd.span3";
            print("Span3 ID: " . str_pad(strtolower(self::largeBaseConvert($span3->id, 10, 16)), 16, '0', STR_PAD_LEFT) . "\n");

            $currentSpan = Span::getCurrent(); // Should generate the OTel spans under the hood
            print("Current Span Id: " . $currentSpan->getContext()->getSpanId() . PHP_EOL);
            $this->assertSame($span3, $currentSpan->getDDSpan());

            print(close_spans_until($span1)); // Closes And Flush span3 and span2
            // span1 is never flushed since never closed
            print("----\n");
            $currentSpan = Span::getCurrent(); // span2 and span3 are closed, span3 is still open and should be the active span
            print("Current Span Id: " . $currentSpan->getContext()->getSpanId() . PHP_EOL);
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
            print("OTel Span Id: " . $OTelSpan->getContext()->getSpanId() . PHP_EOL);

            $ddSpan = start_span();
            $ddSpan->name = "dd.span";
            print("DD Span Id: " . str_pad(strtolower(self::largeBaseConvert($ddSpan->id, 10, 16)), 16, '0', STR_PAD_LEFT) . "\n");

            print("----\n");
            $OTelScope = $OTelSpan->activate();
            print("----\n");

            $currentSpan = Span::getCurrent();
            print("Current Span Id: " . $currentSpan->getContext()->getSpanId() . PHP_EOL);
            $this->assertSame($ddSpan, $currentSpan->getDDSpan());

            $OTelScope->detach();
            $OTelSpan->end();
            Span::getCurrent()->end();
        });

        $spans = $traces[0];
        fwrite(STDERR, json_encode($spans, JSON_PRETTY_PRINT) . PHP_EOL);
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

        //fwrite(STDERR, json_encode($traces, JSON_PRETTY_PRINT) . PHP_EOL);
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
}
