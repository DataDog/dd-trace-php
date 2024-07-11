<?php

declare(strict_types=1);

namespace OpenTelemetry\SDK\Trace;

use DDTrace\OpenTelemetry\API\Trace as DDTraceAPI;

use DDTrace\Tag;
use OpenTelemetry\API\Trace as API;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Attribute\AttributesFactory;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use Throwable;

final class SpanBuilder implements API\SpanBuilderInterface
{
    /**
     * @var non-empty-string
     * @readonly
     */
    private string $spanName;

    /** @readonly */
    private InstrumentationScopeInterface $instrumentationScope;

    /** @readonly */
    private TracerSharedState $tracerSharedState;

    /** @var ContextInterface|false|null */
    private $parentContext = null;

    /**
     * @psalm-var API\SpanKind::KIND_*
     */
    private int $spanKind = API\SpanKind::KIND_INTERNAL;

    /** @var list<LinkInterface> */
    private array $links = [];

    /** @var list<EventInterface> */
    private array $events = [];

    /** @var array */
    private array $attributes;

    private int $totalNumberOfLinksAdded = 0;

    private float $startEpochNanos = 0;

    /** @param non-empty-string $spanName */
    public function __construct(
        string $spanName,
        InstrumentationScopeInterface $instrumentationScope,
        TracerSharedState $tracerSharedState
    ) {
        $this->spanName = $spanName;
        $this->instrumentationScope = $instrumentationScope;
        $this->tracerSharedState = $tracerSharedState;
        $this->attributes = [];
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
        if (!$context->isValid()) {
            return $this;
        }

        $this->totalNumberOfLinksAdded++;

        $this->links[] = new Link(
            $context,
            $this->tracerSharedState
                ->getSpanLimits()
                ->getLinkAttributesFactory()
                ->builder($attributes)
                ->build(),
        );

        return $this;
    }

    public function addEvent(string $name, int $timestamp = null, iterable $attributes = []): SpanBuilderInterface
    {
        $attributesArray = Attributes::create($attributes);
        $nanoTimestamp = ($timestamp !== null && $timestamp < 1e9) ? $timestamp * 1000 : ($timestamp ?? (int)(microtime(true) * 1e9)); // Convert microseconds to nanoseconds if needed
        $this->events[] = new Event($name, $nanoTimestamp, Attributes::create($attributes));
        return $this;
    }

    public function recordException(Throwable $exception, iterable $attributes = []): SpanBuilderInterface
    {
        // Set error metadata
        $this->setAttribute(Tag::ERROR_MSG, $exception->getMessage());
        $this->setAttribute(Tag::ERROR_TYPE, get_class($exception));
        $this->setAttribute(Tag::ERROR_STACK, $exception->getTraceAsString());

        // Standardized exception attributes
        $exceptionAttributes = [
            'exception.message' => $exception->getMessage(),
            'exception.type' => get_class($exception),
            'exception.stacktrace' => $exception->getTraceAsString(),
            'exception.escaped' => false
        ];

        // Merge additional attributes
        $allAttributes = array_merge($exceptionAttributes, iterator_to_array($attributes));

        // Update span metadata based on exception attributes
        $this->updateSpanMeta($allAttributes);

        // Record the exception event
        $this->addEvent('exception', null, $allAttributes);

        return $this;
    }

    private function updateSpanMeta(array $attributes): void
    {
        $this->setAttribute(Tag::ERROR_MSG, $attributes['exception.message'] ?? null);
        $this->setAttribute(Tag::ERROR_TYPE, $attributes['exception.type'] ?? null);
        $this->setAttribute(Tag::ERROR_STACK, $attributes['exception.stacktrace'] ?? null);
    }

    /** @inheritDoc */
    public function setAttribute(string $key, $value): API\SpanBuilderInterface
    {
        $this->attributes[$key] = $value;

        return $this;
    }

    /** @inheritDoc */
    public function setAttributes(iterable $attributes): API\SpanBuilderInterface
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setStartTimestamp(int $timestampNanos): SpanBuilderInterface
    {
        if ($timestampNanos >= 0) {
            $this->startEpochNanos = $timestampNanos / 1000000000;
        }

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
        $this->applySpanKind();

        $parentContext = Context::resolve($this->parentContext);
        $parentSpan = Span::fromContext($parentContext);
        $parentSpanContext = $parentSpan->getContext();

        $span = $parentSpanContext->isValid() ? null : \DDTrace\start_trace_span($this->startEpochNanos);
        $traceId = $parentSpanContext->isValid() ? $parentSpanContext->getTraceId() : \DDTrace\root_span()->traceId;

        $samplingResult = $this
            ->tracerSharedState
            ->getSampler()
            ->shouldSample(
                $parentContext,
                $traceId,
                $this->spanName,
                $this->spanKind,
                Attributes::create($this->attributes),
                $this->links,
                $this->events
            );

        $span = $span ?? \DDTrace\start_trace_span($this->startEpochNanos);
        $samplingDecision = $samplingResult->getDecision();
        $sampled = SamplingResult::RECORD_AND_SAMPLE === $samplingDecision;
        $samplingResultTraceState = $samplingResult->getTraceState();

        if ($parentSpanContext->isValid()) {
            // Traceparent: {2:version}-{32:hex trace id}-{16:hex parent id}-{2:trace_flags}, version always being '00'
            // Since parentSpanContext is valid, the trace identifiers are guaranteed to be in hexadecimal format
            $parentId = $parentSpanContext->getSpanId();
            $traceFlags = $sampled ? '01' : '00';
            $traceParent = "00-$traceId-$parentId-$traceFlags";
            \DDTrace\consume_distributed_tracing_headers([
                'traceparent' => $traceParent,
                'tracestate' => (string) $samplingResultTraceState, // __toString() is implemented in TraceState
            ]);
        } elseif ($samplingResultTraceState) {
            $samplingResultTraceState = $samplingResultTraceState->without('dd');
            \DDTrace\root_span()->tracestate = (string) $samplingResultTraceState;
        }

        $hexSpanId = $span->hexId();
        $spanContext = DDTraceAPI\SpanContext::createFromLocalSpan($span, $sampled, $traceId, $hexSpanId);

        if (!in_array($samplingDecision, [SamplingResult::RECORD_AND_SAMPLE, SamplingResult::RECORD_ONLY], true)) {
            return Span::wrap($spanContext);
        }

        $span->resource = $this->spanName; // OTel.name => DD.resource

        $attributes = $samplingResult->getAttributes();
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }

        return Span::startSpan(
            $span,
            $spanContext,
            $this->instrumentationScope,
            $this->spanKind,
            $parentSpan,
            $parentContext,
            $this->tracerSharedState->getSpanProcessor(),
            $parentSpanContext->isValid() ? ResourceInfoFactory::emptyResource() : $this->tracerSharedState->getResource(),
            $this->attributes,
            $this->links,
            $this->totalNumberOfLinksAdded,
            $this->events
        );
    }

    private function applySpanKind(): void
    {
        switch ($this->spanKind) {
            case API\SpanKind::KIND_CLIENT:
                $this->setAttribute(Tag::SPAN_KIND, Tag::SPAN_KIND_VALUE_CLIENT);
                break;
            case API\SpanKind::KIND_SERVER:
                $this->setAttribute(Tag::SPAN_KIND, Tag::SPAN_KIND_VALUE_SERVER);
                break;
            case API\SpanKind::KIND_PRODUCER:
                $this->setAttribute(Tag::SPAN_KIND, Tag::SPAN_KIND_VALUE_PRODUCER);
                break;
            case API\SpanKind::KIND_CONSUMER:
                $this->setAttribute(Tag::SPAN_KIND, Tag::SPAN_KIND_VALUE_CONSUMER);
                break;
            case API\SpanKind::KIND_INTERNAL:
                $this->setAttribute(Tag::SPAN_KIND, Tag::SPAN_KIND_VALUE_INTERNAL);
                break;
            default:
                break;
        }
    }
}
