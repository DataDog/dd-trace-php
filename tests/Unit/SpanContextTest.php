<?php

namespace DDTrace\Tests\Unit;

use DDTrace\SpanContext;
use DDTrace\Tests\Common\BaseTestCase;

final class SpanContextTest extends BaseTestCase
{
    const BAGGAGE_ITEM_KEY = 'key';
    const BAGGAGE_ITEM_VALUE = 'value';
    const BAGGAGE_ITEM_KEY2 = 'key2';
    const BAGGAGE_ITEM_VALUE2 = 'value2';

    public function testCreateRootSpanContext()
    {
        $context = SpanContext::createAsRoot();
        $this->assertNull($context->getParentId());
    }

    public function testCreateChildSpanContext()
    {
        $parentContext = SpanContext::createAsRoot([
            self::BAGGAGE_ITEM_KEY => self::BAGGAGE_ITEM_VALUE,
        ]);
        $childContext = SpanContext::createAsChild($parentContext);
        $this->assertEquals($childContext->getTraceId(), $parentContext->getTraceId());
        $this->assertEquals($childContext->getParentId(), $parentContext->getSpanId());
        $this->assertEquals(iterator_to_array($childContext), iterator_to_array($parentContext));

        \dd_trace_serialize_closed_spans();
    }

    public function testGetBaggageItemsReturnsExpectedValues()
    {
        $context = SpanContext::createAsRoot([
            self::BAGGAGE_ITEM_KEY => self::BAGGAGE_ITEM_VALUE,
        ]);

        $this->assertEquals(self::BAGGAGE_ITEM_VALUE, $context->getBaggageItem(self::BAGGAGE_ITEM_KEY));
        $this->assertNull($context->getBaggageItem(self::BAGGAGE_ITEM_KEY2));
    }

    public function testAddBaggageItemsReturnsExpectedContext()
    {
        $context = SpanContext::createAsRoot([
            self::BAGGAGE_ITEM_KEY => self::BAGGAGE_ITEM_VALUE,
        ]);

        $newContext = $context->withBaggageItem(self::BAGGAGE_ITEM_KEY2, self::BAGGAGE_ITEM_VALUE2);

        $this->assertEquals(self::BAGGAGE_ITEM_VALUE, $newContext->getBaggageItem(self::BAGGAGE_ITEM_KEY));
        $this->assertNull($context->getBaggageItem(self::BAGGAGE_ITEM_KEY2));
        $this->assertEquals(self::BAGGAGE_ITEM_VALUE2, $newContext->getBaggageItem(self::BAGGAGE_ITEM_KEY2));
    }
}
