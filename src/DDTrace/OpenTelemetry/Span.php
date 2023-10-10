<?php

declare(strict_types=1);

namespace OpenTelemetry\SDK\Trace;

use DDTrace\Propagator;
use DDTrace\SpanData;
use DDTrace\SpanLink;
use DDTrace\Tag;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Attribute\AttributesBuilderInterface;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\EventInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\LinkInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\API\Trace as API;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanLimits;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\StatusData;
use OpenTelemetry\SDK\Trace\StatusDataInterface;
use Throwable;

use function DDTrace\close_span;
use function DDTrace\close_spans_until;
use function DDTrace\generate_distributed_tracing_headers;
use function DDTrace\start_trace_span;
use function DDTrace\trace_id;

final class Span extends API\Span implements ReadWriteSpanInterface
{
    private SpanData $span;

    /** @readonly */
    private API\SpanContextInterface $context;

    /** @readonly */
    private API\SpanContextInterface $parentSpanContext;

    /** @readonly */
    private SpanProcessorInterface $spanProcessor;

    /** @readonly */
    private int $kind;

    /** @readonly */
    private ResourceInfo $resource;

    /** @readonly */
    private InstrumentationScopeInterface $instrumentationScope;

    private AttributesBuilderInterface $attributesBuilder;
    private StatusDataInterface $status;
    private bool $hasEnded = false;

    private function __construct(
        SpanData $span,
        API\SpanContextInterface $context,
        InstrumentationScopeInterface $instrumentationScope,
        int $kind,
        API\SpanContextInterface $parentSpanContext,
        SpanProcessorInterface $spanProcessor,
        ResourceInfo $resource,
        AttributesBuilderInterface $attributesBuilder
    ) {
        $this->span = $span;
        $this->context = $context;
        $this->instrumentationScope = $instrumentationScope;
        $this->kind = $kind;
        $this->parentSpanContext = $parentSpanContext;
        $this->spanProcessor = $spanProcessor;
        $this->resource = $resource;
        $this->attributesBuilder = $attributesBuilder;

        $this->status = StatusData::unset();
    }

    /**
     * This method _MUST_ not be used directly.
     * End users should use a {@see API\TracerInterface} in order to create spans.
     *
     * @param non-empty-string $name
     * @psalm-param API\SpanKind::KIND_* $kind
     * @param list<LinkInterface> $links
     *
     * @internal
     * @psalm-internal OpenTelemetry
     */
    public static function startSpan(
        SpanData $span,
        API\SpanContextInterface $context,
        InstrumentationScopeInterface $instrumentationScope,
        int $kind,
        API\SpanInterface $parentSpan,
        ContextInterface $parentContext,
        SpanProcessorInterface $spanProcessor,
        ResourceInfo $resource,
        AttributesBuilderInterface $attributesBuilder,
        array $links,
        int $totalRecordedLinks,
        float $startEpochNanos = 0
    ): self {
        $attributes = $attributesBuilder->build()->toArray();
        self::_setAttributes($span, $attributes);

        $OTelSpan = new self(
            $span,
            $context,
            $instrumentationScope,
            $kind,
            $parentSpan->getContext(),
            $spanProcessor,
            $resource,
            $attributesBuilder
        );

        // Call onStart here to ensure the span is fully initialized.
        $spanProcessor->onStart($OTelSpan, $parentContext);

        return $OTelSpan;
    }

    public function getName(): string
    {
        return $this->span->name;
    }

    /**
     * @inheritDoc
     */
    public function getContext(): SpanContextInterface
    {
        return $this->context;
    }

    public function getParentContext(): API\SpanContextInterface
    {
        return $this->parentSpanContext;
    }

    public function getInstrumentationScope(): InstrumentationScopeInterface
    {
        return $this->instrumentationScope;
    }

    public function hasEnded(): bool
    {
        return $this->hasEnded;
    }

    /**
     * @inheritDoc
     */
    public function toSpanData(): SpanDataInterface
    {
        return new ImmutableSpan(
            $this,
            $this->getName(),
            [], // TODO: Handle Span Links
            [], // TODO: Handle Span Events
            $this->attributesBuilder->build(),
            0,
            StatusData::create($this->status->getCode(), $this->status->getDescription()),
            $this->hasEnded ? $this->getDuration() : 0,
            $this->hasEnded
        );
    }

    /**
     * @inheritDoc
     */
    public function getDuration(): int
    {
        return $this->hasEnded
            ? $this->span->getDuration()
            : (int) (microtime(true) * 1000000000) - $this->span->getStartTime();
    }

    /**
     * @inheritDoc
     */
    public function getKind(): int
    {
        return $this->kind;
    }

    /**
     * @inheritDoc
     */
    public function getAttribute(string $key)
    {
        return $this->attributesBuilder[$key];

        $meta = $this->span->meta;

        if (isset($meta[$key])) {
            return $meta[$key];
        }

        if (empty($meta)) {
            return null;
        }

        // Support for nested attributes through dot notation
        $prefix = '';
        $parts = explode('.', $key);
        foreach ($parts as $part) {
            $prefix .= $part;
            if (isset($meta[$prefix])) {
                return $meta[$prefix];
            } else {
                $prefix .= '.';
            }
        }

        return null;
    }

    public function getStartEpochNanos(): int
    {
        return $this->span->getStartTime();
    }

    /**
     * @inheritDoc
     */
    public function isRecording(): bool
    {
        return !$this->hasEnded;
    }

    private static function _setAttributes(SpanData $span, iterable $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $span->meta[$key] = $value;
        }
    }

    /**
     * @inheritDoc
     */
    public function setAttribute(string $key, $value): SpanInterface
    {
        if (!$this->hasEnded) {
            $this->attributesBuilder[$key] = $value;
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setAttributes(iterable $attributes): SpanInterface
    {
        foreach ($attributes as $key => $value) {
            $this->attributesBuilder[$key] = $value;
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function addEvent(string $name, iterable $attributes = [], int $timestamp = null): SpanInterface
    {
        // no-op
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function recordException(Throwable $exception, iterable $attributes = []): SpanInterface
    {
        if ($this->hasEnded) {
            return $this;
        }

        $this->span->meta[Tag::ERROR_MSG] = $exception->getMessage();
        $this->span->meta[Tag::ERROR_TYPE] = get_class($exception);
        $this->span->meta[Tag::ERROR_STACK] = $exception->getTraceAsString();

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function updateName(string $name): SpanInterface
    {
        if (!$this->hasEnded) {
            $this->span->name = $name;
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setStatus(string $code, string $description = null): SpanInterface
    {
        if ($this->hasEnded) {
            return $this;
        }

        // An attempt to set value Unset SHOULD be ignored.
        if ($code === API\StatusCode::STATUS_UNSET) {
            return $this;
        }

        // When span status is set to Ok it SHOULD be considered final and any further attempts to change it SHOULD be ignored.
        if ($this->status->getCode() === API\StatusCode::STATUS_OK) {
            return $this;
        }

        if ($this->status->getCode() === API\StatusCode::STATUS_UNSET && $code === API\StatusCode::STATUS_ERROR) {
            $this->span->meta[Tag::ERROR_MSG] = $description;
        } elseif ($this->status->getCode() === API\StatusCode::STATUS_ERROR && $code === API\StatusCode::STATUS_OK) {
            unset($this->span->meta[Tag::ERROR_MSG]);
        }

        $this->status = StatusData::create($code, $description);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function end(int $endEpochNanos = null): void
    {
        if ($this->hasEnded) {
            return;
        }

        $attributes = $this->attributesBuilder->build()->toArray();
        self::_setAttributes($this->span, $attributes);

        // TODO: Actually check if the span was closed (change extension to return a boolean?)
        close_spans_until($this->span);
        close_span();
        //close_span($endEpochNanos !== null ? $endEpochNanos / 1000000000 : 0);
        $this->hasEnded = true;

        $this->spanProcessor->onEnd($this);
    }

    public function getResource(): ResourceInfo
    {
        return $this->resource;
    }
}
