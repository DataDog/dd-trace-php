<?php

namespace DDTrace\Tests\OpenTelemetry\Integration\API;

use DDTrace\Tag;
use DDTrace\Tests\Common\BaseTestCase;
use DDTrace\Tests\Common\SpanAssertion;
use DDTrace\Tests\Common\SpanAssertionTrait;
use DDTrace\Tests\Common\TracerTestTrait;
use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Trace\TracerProvider;

final class BaggageTest extends BaseTestCase
{
    use TracerTestTrait, SpanAssertionTrait;

    public function testBasicUsage()
    {
        $propagator = BaggagePropagator::getInstance();
        $scopes = [];

        // Extract baggage from a carrier (e.g., inbound HTTP request), and store in context
        $carrier = [
            'baggage' => 'key1=value1,key2=value2;property1',
        ];
        $context = $propagator->extract($carrier);
        $scopes[] = $context->activate();

        // Get the baggage, and extract values from it
        $baggage = Baggage::getCurrent();
        $key1 = $baggage->getValue('key1');
        $this->assertSame('value1', $key1);
        $key2 = $baggage->getValue('key2');
        $this->assertSame('value2', $key2);
        $key1Metadata = $baggage->getEntry('key1')->getMetadata()->getValue();
        $this->assertSame('', $key1Metadata);
        $key2Metadata = $baggage->getEntry('key2')->getMetadata()->getValue();
        $this->assertSame('property1', $key2Metadata);

        // Remove a value from baggage and add a value
        $scopes[] = $baggage->toBuilder()->remove('key1')->set('key3', 'value3')->build()->activate();

        // Extract baggage from context, and store in a different carrier (e.g., outbound http request headers)
        $out = [];
        $propagator->inject($out);
        $this->assertSame('key2=value2;property1,key3=value3', $out['baggage']);

        // Clear baggage (to avoid sending to an untrusted process)
        $scopes[] = Baggage::getEmpty()->activate();
        $cleared = [];
        $propagator->extract($cleared);
        $this->assertEmpty($cleared);

        // Detach scopes
        foreach (array_reverse($scopes) as $scope) {
            $scope->detach();
        }
    }

    public function testSpansAndBaggage()
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

            $childSpan = $tracer->spanBuilder('child')->startSpan();
            $childSpanScope = $childSpan->activate();

            $childSpan->setAttribute('user.id', Baggage::getCurrent()->getValue('user.id'));

            $childSpanScope->detach();
            $childSpan->end();

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
                    SpanAssertion::exists('internal', 'child', false, 'datadog/dd-trace-tests')
                ])
        ]);
    }
}
