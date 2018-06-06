<?php

namespace DDTrace\Tests\Unit\Encoders;

use DDTrace\Encoders\Json;
use DDTrace\Span;
use DDTrace\SpanContext;
use PHPUnit_Framework_TestCase;

final class JsonTest extends PHPUnit_Framework_TestCase
{
    public function testEncodeTracesSuccess()
    {
        $expectedPayload = <<<JSON
[[{"trace_id":1589331357723252209,"span_id":1589331357723252210,"name":"test_name","resource":"test_resource",
JSON
            .    <<<JSON
"service":"test_service","start":1518038421211969000,"error":0}]]
JSON;

        $context = new SpanContext('160e7072ff7bd5f1', '160e7072ff7bd5f2');
        $span = new Span(
            'test_name',
            $context,
            'test_service',
            'test_resource',
            1518038421211969
        );
        $jsonEncoder = new Json();
        $encodedTrace = $jsonEncoder->encodeTraces([[$span]]);
        $this->assertEquals($expectedPayload, $encodedTrace);
    }

    public function testEncodeIgnoreSpanWhenEncodingFails()
    {
        $expectedPayload = '[[]]';

        $context = new SpanContext('160e7072ff7bd5f1', '160e7072ff7bd5f2');
        $span = new Span(
            'test_name',
            $context,
            'test_service',
            'test_resource',
            1518038421211969
        );
        // this will generate a malformed UTF-8 string
        $span->setTag('invalid', hex2bin('37f2bef0ab085308'));

        $jsonEncoder = new Json();
        $encodedTrace = $jsonEncoder->encodeTraces([[$span, $span]]);
        $this->assertEquals($expectedPayload, $encodedTrace);
    }
}
