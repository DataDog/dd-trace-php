<?php

namespace DDTrace\Tests\OpenTracerUnit;

use DDTrace\OpenTracer\SpanContext;
use DDTrace\SpanContext as DDSpanContext;
use PHPUnit\Framework\TestCase;

final class SpanContextTest extends TestCase
{
    const BAGGAGE_ITEM_KEY = 'key';
    const BAGGAGE_ITEM_VALUE = 'value';
    const BAGGAGE_ITEM_KEY2 = 'key2';
    const BAGGAGE_ITEM_VALUE2 = 'value2';

    public function testCreateRootSpanContext()
    {
        $context = new SpanContext(
            DDSpanContext::createAsRoot()
        );
        $this->assertNull($context->unwrapped()->getParentId());
    }

    public function testCreateChildSpanContext()
    {
        $parentContext = new SpanContext(
            DDSpanContext::createAsRoot([
                self::BAGGAGE_ITEM_KEY => self::BAGGAGE_ITEM_VALUE,
            ])
        );
        $childContext = new SpanContext(
            DDSpanContext::createAsChild($parentContext->unwrapped())
        );

        $this->assertSame($childContext->unwrapped()->getTraceId(), $parentContext->unwrapped()->getTraceId());
        $this->assertSame($childContext->unwrapped()->getParentId(), $parentContext->unwrapped()->getSpanId());
        $this->assertSame(iterator_to_array($childContext), iterator_to_array($parentContext));
    }

    public function testGetBaggageItemsReturnsExpectedValues()
    {
        $context = new SpanContext(
            DDSpanContext::createAsRoot([
                self::BAGGAGE_ITEM_KEY => self::BAGGAGE_ITEM_VALUE,
            ])
        );

        $this->assertSame(self::BAGGAGE_ITEM_VALUE, $context->getBaggageItem(self::BAGGAGE_ITEM_KEY));
        $this->assertNull($context->getBaggageItem(self::BAGGAGE_ITEM_KEY2));
    }

    public function testAddBaggageItemsReturnsExpectedContext()
    {
        $context = new SpanContext(
            DDSpanContext::createAsRoot([
                self::BAGGAGE_ITEM_KEY => self::BAGGAGE_ITEM_VALUE,
            ])
        );
        $newContext = $context->withBaggageItem(self::BAGGAGE_ITEM_KEY2, self::BAGGAGE_ITEM_VALUE2);

        $this->assertSame(self::BAGGAGE_ITEM_VALUE, $newContext->getBaggageItem(self::BAGGAGE_ITEM_KEY));
        $this->assertNull($context->getBaggageItem(self::BAGGAGE_ITEM_KEY2));
        $this->assertSame(self::BAGGAGE_ITEM_VALUE2, $newContext->getBaggageItem(self::BAGGAGE_ITEM_KEY2));
    }
}
