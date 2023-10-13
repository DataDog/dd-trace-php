<?php

declare(strict_types=1);

namespace OpenTelemetry\SDK\Trace;

use DDTrace\OpenTelemetry\API\Trace as DDTraceAPI;

use DDTrace\Log\Logger;
use DDTrace\Propagator;
use DDTrace\Sampling\PrioritySampling;
use DDTrace\Tag;
use OpenTelemetry\API\Trace as API;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Attribute\AttributesBuilderInterface;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;

use OpenTelemetry\SDK\Trace\LinkInterface;
use OpenTelemetry\SDK\Trace\TracerSharedState;
use function DDTrace\consume_distributed_tracing_headers;
use function DDTrace\start_span;
use function DDTrace\start_trace_span;
use function DDTrace\trace_id;

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

    private AttributesBuilderInterface $attributesBuilder;
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
        $this->attributesBuilder = $tracerSharedState->getSpanLimits()->getAttributesFactory()->builder();
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

        switch ($spanKind) {
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

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function startSpan(): SpanInterface
    {
        // TODO: Check w/. setParent(false)
        $parentContext = Context::resolve($this->parentContext);
        $parentSpan = Span::fromContext($parentContext);
        $parentSpanContext = $parentSpan->getContext();

        $span = \DDTrace\start_trace_span($this->startEpochNanos);
        if ($parentSpanContext->isValid()) {
            $traceId = $parentSpanContext->getTraceId();
        } else {
            $traceId = str_pad(strtolower(self::largeBaseConvert(trace_id(), 10, 16)), 32, '0', STR_PAD_LEFT);
        }

        $samplingResult = $this
            ->tracerSharedState
            ->getSampler()
            ->shouldSample(
                $parentContext,
                $traceId,
                $this->spanName,
                $this->spanKind,
                $this->attributesBuilder->build(),
                $this->links,
            );
        $samplingDecision = $samplingResult->getDecision();
        $sampled = SamplingResult::RECORD_AND_SAMPLE === $samplingDecision;
        $samplingResultTraceState = $samplingResult->getTraceState();

        if ($parentSpanContext->isValid()) {
            // Traceparent: {version}-{hex trace id}-{hex parent id}-{trace_flags}, version always being '00'
            // Since parentSpanContext is valid, the trace identifiers are guaranteed to be in hexadecimal format
            $parentId = $parentSpanContext->getSpanId();
            $traceFlags = $sampled ? '01' : '00';
            $traceParent = "00-$traceId-$parentId-$traceFlags";
            \DDTrace\consume_distributed_tracing_headers([
                'traceparent' => $traceParent,
                'tracestate' => (string) $samplingResultTraceState, // __toString() is implemented in TraceState
            ]);
        } else {
            \DDTrace\consume_distributed_tracing_headers([
                'tracestate' => (string) $samplingResultTraceState, // __toString() is implemented in TraceState
            ]);
        }

        $hexSpanId = str_pad(strtolower(self::largeBaseConvert($span->id, 10, 16)), 16, '0', STR_PAD_LEFT);
        $spanContext = DDTraceAPI\SpanContext::createFromLocalSpan($span, $sampled, $traceId, $hexSpanId);

        if (!in_array($samplingDecision, [SamplingResult::RECORD_AND_SAMPLE, SamplingResult::RECORD_ONLY], true)) {
            return Span::wrap($spanContext);
        }

        $span->name = $this->spanName;

        $attributesBuilder = clone $this->attributesBuilder; // According to OTel's spec, attributes can't be changed after span creation...
        foreach ($samplingResult->getAttributes() as $key => $value) {
            $attributesBuilder[$key] = $value;
        }

        return Span::startSpan(
            $span,
            $spanContext,
            $this->instrumentationScope,
            $this->spanKind,
            $parentSpan,
            $parentContext,
            $this->tracerSharedState->getSpanProcessor(),
            $this->tracerSharedState->getResource(),
            $attributesBuilder,
            $this->links,
            0,
            $this->startEpochNanos
        );
    }

    // Source: https://magp.ie/2015/09/30/convert-large-integer-to-hexadecimal-without-php-math-extension/
    private static function largeBaseConvert($numString, $fromBase, $toBase)
    {
        $chars = "0123456789abcdefghijklmnopqrstuvwxyz";
        $toString = substr($chars, 0, $toBase);

        $length = strlen($numString);
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $number[$i] = strpos($chars, $numString[$i]);
        }
        do {
            $divide = 0;
            $newLen = 0;
            for ($i = 0; $i < $length; $i++) {
                $divide = $divide * $fromBase + $number[$i];
                if ($divide >= $toBase) {
                    $number[$newLen++] = (int)($divide / $toBase);
                    $divide = $divide % $toBase;
                } elseif ($newLen > 0) {
                    $number[$newLen++] = 0;
                }
            }
            $length = $newLen;
            $result = $toString[$divide] . $result;
        } while ($newLen != 0);

        return $result;
    }
}
