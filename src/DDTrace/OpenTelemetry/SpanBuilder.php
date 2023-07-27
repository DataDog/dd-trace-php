<?php

declare(strict_types=1);

namespace DDTrace\OpenTelemetry\SDK\Trace;

use DDTrace\Propagator;
use OpenTelemetry\API\Trace as API;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Attribute\AttributesBuilderInterface;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;

use function DDTrace\consume_distributed_tracing_headers;
use function DDTrace\start_span;
use function DDTrace\start_trace_span;

final class SpanBuilder implements API\SpanBuilderInterface
{
    /**
     * @var non-empty-string
     * @readonly
     */
    private string $spanName;

    /** @readonly */
    private InstrumentationScopeInterface $instrumentationScope;

    /** @var ContextInterface|false|null */
    private $parentContext = null;

    /**
     * @psalm-var API\SpanKind::KIND_*
     */
    private int $spanKind = API\SpanKind::KIND_INTERNAL;

    private AttributesBuilderInterface $attributesBuilder;
    private int $startTime = 0;

    /** @param non-empty-string $spanName */
    public function __construct(
        string $spanName,
        InstrumentationScopeInterface $instrumentationScope
    ) {
        $this->spanName = $spanName;
        $this->instrumentationScope = $instrumentationScope;
        $this->attributesBuilder = Attributes::factory()->builder();
    }

    /**
     * @inheritDoc
     */
    public function setParent($context): API\SpanBuilderInterface
    {
        $this->parentContext = $context;

        return $this;
    }

    public function addLink(SpanContextInterface $context, iterable $attributes = []): SpanBuilderInterface
    {
        // TODO: Span Links are future works

        return $this;
    }

    /** @inheritDoc */
    public function setAttribute(string $key, $value): API\SpanBuilderInterface
    {
        $this->attributesBuilder[$key] = $value;

        return $this;
    }

    /** @inheritDoc */
    public function setAttributes(iterable $attributes): API\SpanBuilderInterface
    {
        foreach ($attributes as $key => $value) {
            $this->attributesBuilder[$key] = $value;
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setStartTimestamp(int $timestampNanos): SpanBuilderInterface
    {
        if ($timestampNanos < 0) {
            return $this;
        }

        $this->startTime = $timestampNanos / 1000000000;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setSpanKind(int $spanKind): SpanBuilderInterface
    {
        $this->spanKind = $spanKind;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function startSpan(): SpanInterface
    {
        if ($this->parentContext === false) {
            $span = start_trace_span();
        } else {
            $span = start_span();
        }

        $parentContext = Context::resolve($this->parentContext);
        $parentSpan = Span::fromContext($parentContext);
        $parentSpanContext = $parentSpan->getContext();

        if ($parentSpanContext->isValid()) {
            $headers = [
                Propagator::DEFAULT_TRACE_ID_HEADER => $parentSpanContext->getTraceId(),
                Propagator::DEFAULT_PARENT_ID_HEADER => $parentSpanContext->getSpanId(),
                "tracestate" => $parentSpanContext->getTraceState(),
            ];

            // TODO: Handle Sampling

            consume_distributed_tracing_headers($headers);
        }

        $spanContext = API\SpanContext::create(
            \DDTrace\trace_id(),
            $span->id,
            API\TraceFlags::DEFAULT, // TODO: Handle Sampling
            $parentSpanContext->isValid() ? new API\TraceState($parentSpanContext->getTraceState()) : null // TODO: Handle Sampling
        );

        return Span::startSpan(
            $span,
            $spanContext,
            $this->instrumentationScope,
            $this->spanKind,
            $parentSpan,
            $parentContext,
            $this->tracerSharedState->getResource(),
            $this->attributesBuilder,
            [],
            0,
            $this->startTime
        );
    }
}