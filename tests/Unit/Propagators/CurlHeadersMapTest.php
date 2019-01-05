<?php

namespace DDTrace\Tests\Unit\Propagators;

use DDTrace\Propagators\CurlHeadersMap;
use DDTrace\SpanContext;
use DDTrace\Tests\DebugTransport;
use DDTrace\Tracer;
use DDTrace\GlobalTracer;
use PHPUnit\Framework;

final class CurlHeadersMapTest extends Framework\TestCase
{
    const BAGGAGE_ITEM_KEY = 'test_key';
    const BAGGAGE_ITEM_VALUE = 'test_value';
    const TRACE_ID = '1589331357723252209';
    const SPAN_ID = '1589331357723252210';

    /**
     * @var Tracer
     */
    private $tracer;

    protected function setUp()
    {
        parent::setUp();
        $this->tracer = new Tracer(new DebugTransport());
        GlobalTracer::set($this->tracer);
    }

    public function testInjectSpanContextIntoCarrier()
    {

        $rootContext = SpanContext::createAsRoot([self::BAGGAGE_ITEM_KEY => self::BAGGAGE_ITEM_VALUE]);
        $context = SpanContext::createAsChild($rootContext);

        $carrier = [];

        (new CurlHeadersMap($this->tracer))->inject($context, $carrier);

        $this->assertEquals([
            'x-datadog-trace-id: ' . $rootContext->getTraceId(),
            'x-datadog-parent-id: ' . $context->getSpanId(),
            'ot-baggage-' . self::BAGGAGE_ITEM_KEY . ': ' . self::BAGGAGE_ITEM_VALUE,
        ], array_values($carrier));
    }

    public function testExistingUserHeadersAreHonored()
    {

        $rootContext = SpanContext::createAsRoot([self::BAGGAGE_ITEM_KEY => self::BAGGAGE_ITEM_VALUE]);
        $context = SpanContext::createAsChild($rootContext);

        $carrier = [
            'existing: headers',
        ];

        (new CurlHeadersMap($this->tracer))->inject($context, $carrier);

        $this->assertEquals([
            'existing: headers',
            'x-datadog-trace-id: ' . $rootContext->getTraceId(),
            'x-datadog-parent-id: ' . $context->getSpanId(),
            'ot-baggage-' . self::BAGGAGE_ITEM_KEY . ': ' . self::BAGGAGE_ITEM_VALUE,
        ], array_values($carrier));
    }

    public function testExistingDistributedTracingHeadersAreReplaced()
    {

        $rootContext = SpanContext::createAsRoot([self::BAGGAGE_ITEM_KEY => self::BAGGAGE_ITEM_VALUE]);
        $context = SpanContext::createAsChild($rootContext);

        $carrier = [
            'existing: headers',
            'x-datadog-trace-id: trace',
            'x-datadog-parent-id: parent',
            'ot-baggage-' . self::BAGGAGE_ITEM_KEY . ': baggage',
        ];

        (new CurlHeadersMap($this->tracer))->inject($context, $carrier);

        $this->assertEquals([
            'existing: headers',
            'x-datadog-trace-id: ' . $rootContext->getTraceId(),
            'x-datadog-parent-id: ' . $context->getSpanId(),
            'ot-baggage-' . self::BAGGAGE_ITEM_KEY . ': ' . self::BAGGAGE_ITEM_VALUE,
        ], array_values($carrier));
    }
}
