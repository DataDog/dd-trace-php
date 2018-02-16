<?php

namespace DDTrace\Tests\Unit\Propagators;

use DDTrace\Propagators\TextMap;
use DDTrace\SpanContext;
use PHPUnit_Framework_TestCase;

final class TextMapTest extends PHPUnit_Framework_TestCase
{
    const BAGGAGE_ITEM_KEY = 'test_key';
    const BAGGAGE_ITEM_VALUE = 'test_value';
    const TRACE_ID = '1c42b4de015cc315';
    const SPAN_ID = '1c42b4de015cc316';

    public function testInjectSpanContextIntoCarrier()
    {
        $context = SpanContext::createAsRoot([self::BAGGAGE_ITEM_KEY => self::BAGGAGE_ITEM_VALUE]);
        $carrier = [];
        $textMapPropagator = new TextMap();
        $textMapPropagator->inject($context, $carrier);
        $this->assertEquals([
            'x-datadog-trace-id' => $context->getTraceId(),
            'x-datadog-parent-id' => $context->getSpanId(),
            'ot-baggage-' . self::BAGGAGE_ITEM_KEY => self::BAGGAGE_ITEM_VALUE,
        ], $carrier);
    }

    public function testExtractSpanContextFromCarrier()
    {
        $carrier = [
            'x-datadog-trace-id' => self::TRACE_ID,
            'x-datadog-parent-id' => self::SPAN_ID,
            'ot-baggage-' . self::BAGGAGE_ITEM_KEY => self::BAGGAGE_ITEM_VALUE,
        ];
        $textMapPropagator = new TextMap();
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
}
