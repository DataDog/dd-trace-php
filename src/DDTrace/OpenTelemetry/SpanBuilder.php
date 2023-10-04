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
    private int $startEpochNanos = 0;

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
        if ($timestampNanos < 0) {
            return $this;
        }

        $this->startEpochNanos = $timestampNanos / 1000000000;

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

        $headers = \DDTrace\generate_distributed_tracing_headers();
        $traceFlags = isset($headers[Propagator::DEFAULT_SAMPLING_PRIORITY_HEADER]) ? API\TraceFlags::SAMPLED : API\TraceFlags::DEFAULT;
        $traceState = isset($headers["tracestate"]) ? new API\TraceState($headers["tracestate"]) : null; // TODO: Check if the parsing is correct

        $spanContext = API\SpanContext::create(
            str_pad(strtolower(self::largeBaseConvert(trace_id(), 10, 16)), 32, '0', STR_PAD_LEFT),
            str_pad(strtolower(self::largeBaseConvert(dd_trace_peek_span_id(), 10, 16)), 16, '0', STR_PAD_LEFT),
            $traceFlags,
            $parentSpanContext->isValid() ? new API\TraceState($parentSpanContext->getTraceState()) : $traceState // TODO: Handle Sampling
        );

        return Span::startSpan(
            $span,
            $spanContext,
            $this->instrumentationScope,
            $this->spanKind,
            $parentContext,
            $this->attributesBuilder,
            [],
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
