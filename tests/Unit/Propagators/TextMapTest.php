<?php

namespace DDTrace\Tests\Unit\Propagators;

use DDTrace\Propagators\TextMap;
use DDTrace\SpanContext;
use DDTrace\Tests\DebugTransport;
use DDTrace\Tracer;
use DDTrace\GlobalTracer;
use DDTrace\Tests\Common\BaseTestCase;

final class TextMapTest extends BaseTestCase
{
    const BAGGAGE_ITEM_KEY = 'test_key';
    const BAGGAGE_ITEM_VALUE = 'test_value';
    const TRACE_ID = '1589331357723252209';
    const SPAN_ID = '1589331357723252210';

    /**
     * @var Tracer
     */
    private $tracer;

    protected function ddSetUp()
    {
        parent::ddSetUp();
        $this->tracer = new Tracer(new DebugTransport());
        GlobalTracer::set($this->tracer);
    }

    public function testInjectSpanContextIntoCarrier()
    {
        $context = SpanContext::createAsRoot([self::BAGGAGE_ITEM_KEY => self::BAGGAGE_ITEM_VALUE]);
        $carrier = [];
        $textMapPropagator = new TextMap($this->tracer);
        $textMapPropagator->inject($context, $carrier);
        $this->assertEquals([
            'x-datadog-trace-id' => $context->getTraceId(),
            'x-datadog-parent-id' => $context->getSpanId(),
            'ot-baggage-' . self::BAGGAGE_ITEM_KEY => self::BAGGAGE_ITEM_VALUE,
            'x-datadog-sampling-priority' => 1,
        ], $carrier);
    }

    public function testExtractSpanContextFromCarrierFailsDueToLackOfTraceId()
    {
        $carrier = [
            'x-datadog-parent-id' => self::SPAN_ID,
            'ot-baggage-' . self::BAGGAGE_ITEM_KEY => self::BAGGAGE_ITEM_VALUE,
        ];
        $textMapPropagator = new TextMap($this->tracer);
        $context = $textMapPropagator->extract($carrier);
        $this->assertNull($context);
    }

    public function testExtractSpanContextFromCarrierFailsDueToLackOfParentId()
    {
        $carrier = [
            'x-datadog-trace-id' => self::TRACE_ID,
            'ot-baggage-' . self::BAGGAGE_ITEM_KEY => self::BAGGAGE_ITEM_VALUE,
        ];
        $textMapPropagator = new TextMap($this->tracer);
        $context = $textMapPropagator->extract($carrier);
        $this->assertNull($context);
    }

    public function testExtractSpanContextFromCarrierSuccess()
    {
        $carrier = [
            'x-datadog-trace-id' => self::TRACE_ID,
            'x-datadog-parent-id' => self::SPAN_ID,
            'ot-baggage-' . self::BAGGAGE_ITEM_KEY => self::BAGGAGE_ITEM_VALUE,
        ];
        $textMapPropagator = new TextMap($this->tracer);
        $context = $textMapPropagator->extract($carrier);
        $this->assertTrue(
            $context->isEqual(new SpanContext(
                self::TRACE_ID,
                self::SPAN_ID,
                null,
                [self::BAGGAGE_ITEM_KEY => self::BAGGAGE_ITEM_VALUE]
            ))
        );
    }

    public function testExtractPrioritySampling()
    {
        $carrier = [
            'x-datadog-trace-id' => self::TRACE_ID,
            'x-datadog-parent-id' => self::SPAN_ID,
            'x-datadog-sampling-priority' => '2',
        ];
        $textMapPropagator = new TextMap($this->tracer);
        $context = $textMapPropagator->extract($carrier);
        $this->assertSame(2, $context->getPropagatedPrioritySampling());
    }

    public function testExtractPrioritySamplingWhenNotProvided()
    {
        $carrier = [
            'x-datadog-trace-id' => self::TRACE_ID,
            'x-datadog-parent-id' => self::SPAN_ID,
        ];
        $textMapPropagator = new TextMap($this->tracer);
        $context = $textMapPropagator->extract($carrier);
        $this->assertSame(null, $context->getPropagatedPrioritySampling());
    }

    public function testOriginIsPropagated()
    {
        $rootContext = SpanContext::createAsRoot();
        $rootContext->origin = 'foo_origin';
        $context = SpanContext::createAsChild($rootContext);

        $carrier = [];
        $textMapPropagator = new TextMap($this->tracer);
        $textMapPropagator->inject($context, $carrier);

        $this->assertSame('foo_origin', $carrier['x-datadog-origin']);

        \dd_trace_serialize_closed_spans();
    }

    public function testOriginIsExtracted()
    {
        $carrier = [
            'x-datadog-trace-id' => self::TRACE_ID,
            'x-datadog-parent-id' => self::SPAN_ID,
            'x-datadog-origin' => 'foo_origin',
        ];
        $textMapPropagator = new TextMap($this->tracer);
        $context = $textMapPropagator->extract($carrier);

        $this->assertSame('foo_origin', $context->origin);
    }
}
