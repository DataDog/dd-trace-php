<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Unit\Extension\Propagator\W3C;

use DDTrace\Tests\Common\BaseTestCase;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TraceState;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Trace\Span;

/**
 * @covers \OpenTelemetry\Extension\Propagator\W3C\W3CPropagator
 */
class W3CPropagatorTest extends BaseTestCase
{
    private const W3C_TRACE_ID_16_CHAR = 'ff00051791e00041';
    private const W3C_TRACE_ID = 'ff0000000000051791e0000000000041';
    private const W3C_SPAN_ID = 'ff00051791e00041';
    private const W3C_SINGLE_HEADER_SAMPLED = '00' . '-' . self::W3C_TRACE_ID . '-' . self::W3C_SPAN_ID . '-01';
    private const IS_SAMPLED = '1';
    private const IS_NOT_SAMPLED = '0';

    private $W3C;
    private $TRACE_ID;
    private $SPAN_ID;
    private $SAMPLED;

    protected function ddSetUp()
    {
        dd_trace_close_all_spans_and_flush();
        parent::ddSetUp();
        self::putenv('DD_TRACE_GENERATE_ROOT_SPAN=0');
        dd_trace_internal_fn('ddtrace_reload_config');
    }

    protected function ddTearDown()
    {
        parent::ddTearDown();
        self::putenv('DD_TRACE_GENERATE_ROOT_SPAN');
        dd_trace_internal_fn('ddtrace_reload_config');
    }

    public function setUp(): void
    {
        [$this->W3C] = TraceContextPropagator::getInstance()->fields();
    }

    public function test_w3c_inject(): void
    {
        $propagator = TraceContextPropagator::getInstance();
        $carrier = [];
        $propagator->inject(
            $carrier,
            null,
            $this->withSpanContext(
                SpanContext::create(self::W3C_TRACE_ID, self::W3C_SPAN_ID, TraceFlags::SAMPLED),
                Context::getCurrent()
            )
        );

        $this->assertSame(
            [$this->W3C => self::W3C_SINGLE_HEADER_SAMPLED],
            $carrier
        );
    }

    public function test_extract_only_w3c_sampled_context_with_w3c_instance(): void
    {
        $carrier = [
            $this->W3C => self::W3C_SINGLE_HEADER_SAMPLED
        ];

        $propagator = TraceContextPropagator::getInstance();

        $context = $propagator->extract($carrier);

        $this->assertEquals(
            SpanContext::createFromRemoteParent(self::W3C_TRACE_ID, self::W3C_SPAN_ID, TraceFlags::SAMPLED),
            $this->getSpanContext($context)
        );
    }

    public function test_extract_only_w3c_sampled_context_with_w3c_instance_and_tracestate(): void
    {
        $carrier = [
            $this->W3C => self::W3C_SINGLE_HEADER_SAMPLED,
            'tracestate' => 'foo=bar'
        ];

        $propagator = TraceContextPropagator::getInstance();

        $context = $propagator->extract($carrier);

        $this->assertEquals(
            SpanContext::createFromRemoteParent(self::W3C_TRACE_ID, self::W3C_SPAN_ID, TraceFlags::SAMPLED, new TraceState('foo=bar')),
            $this->getSpanContext($context)
        );
    }

    public function test_extract_only_w3c_sampled_context_with_traceparent(): void
    {
        $carrier = [
            TraceContextPropagator::TRACEPARENT => self::W3C_SINGLE_HEADER_SAMPLED
        ];

        $propagator = TraceContextPropagator::getInstance();

        $context = $propagator->extract($carrier);

        $this->assertEquals(
            SpanContext::createFromRemoteParent(self::W3C_TRACE_ID, self::W3C_SPAN_ID, TraceFlags::SAMPLED),
            $this->getSpanContext($context)
        );
    }

    public function test_extract_only_w3c_sampled_context_with_invalid_traceparent(): void
    {
        $carrier = [
            TraceContextPropagator::TRACEPARENT => 'invalid'
        ];

        $propagator = TraceContextPropagator::getInstance();

        $context = $propagator->extract($carrier);

        $this->assertEquals(
            SpanContext::getInvalid(),
            $this->getSpanContext($context)
        );
    }

    private function getSpanContext(ContextInterface $context): SpanContextInterface
    {
        return Span::fromContext($context)->getContext();
    }

    private function withSpanContext(SpanContextInterface $spanContext, ContextInterface $context): ContextInterface
    {
        return $context->withContextValue(Span::wrap($spanContext));
    }
}
