<?php

declare(strict_types=1);

namespace OpenTelemetry\SDK\Trace;

use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Util\Convention;
use DDTrace\Util\ObjectKVStore;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Attribute\AttributesBuilderInterface;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\API\Trace as API;
use Throwable;

use function DDTrace\close_span;
use function DDTrace\switch_stack;

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

    private StatusDataInterface $status;

    private function __construct(
        SpanData $span,
        API\SpanContextInterface $context,
        InstrumentationScopeInterface $instrumentationScope,
        int $kind,
        API\SpanContextInterface $parentSpanContext,
        SpanProcessorInterface $spanProcessor,
        ResourceInfo $resource
    ) {
        $this->span = $span;
        $this->context = $context;
        $this->instrumentationScope = $instrumentationScope;
        $this->kind = $kind;
        $this->parentSpanContext = $parentSpanContext;
        $this->spanProcessor = $spanProcessor;
        $this->resource = $resource;

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
        float $startEpochNanos = 0,
        bool $isRemapped = true // Answers the question "Was the span created using the OTel API?"
    ): self {
        $attributes = $attributesBuilder->build()->toArray();
        self::_setAttributes($span, $attributes);

        $resourceAttributes = $resource->getAttributes()->toArray();
        self::_setAttributes($span, $resourceAttributes);

        if ($isRemapped || empty($span->name)) {
            // Since the span was created using the OTel API, it doesn't have an operation name*
            // *: This is a bit false. For instance, a span created using the OTel API and DD_TRACE_GENERATE_ROOT_SPAN=0
            // under a cli (e.g., phpunit) process would have a default operation name set (e.g., phpunit). This is
            // done in serializer.c:ddtrace_set_root_span_properties (as of v0.92.0)
            $span->name = Convention::defaultOperationName($span);
        }

        $OTelSpan = new self(
            $span,
            $context,
            $instrumentationScope,
            $kind,
            $parentSpan->getContext(),
            $spanProcessor,
            $resource
        );

        ObjectKVStore::put($span, 'otel_span', $OTelSpan);

        // Call onStart here to ensure the span is fully initialized.
        $spanProcessor->onStart($OTelSpan, $parentContext);

        return $OTelSpan;
    }

    public function getName(): string
    {
        return $this->span->resource ?: $this->span->name;
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
        return $this->span->getDuration() !== 0;
    }

    /**
     * @inheritDoc
     */
    public function toSpanData(): SpanDataInterface
    {
        $hasEnded = $this->hasEnded();



        return new ImmutableSpan(
            $this,
            $this->getName(),
            [], // TODO: Handle Span Links
            [], // TODO: Handle Span Events
            Attributes::create(array_merge($this->span->meta, $this->span->metrics)),
            0,
            StatusData::create($this->status->getCode(), $this->status->getDescription()),
            $hasEnded ? $this->span->getStartTime() + $this->span->getDuration() : 0,
            $this->hasEnded()
        );
    }

    /**
     * @inheritDoc
     */
    public function getDuration(): int
    {
        return $this->span->getDuration() ?: ClockFactory::getDefault()->now() - $this->span->getStartTime();
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
        return $this->span->meta[$key] ?? ($this->span->metrics[$key] ?? null);
    }

    public function getStartEpochNanos(): int
    {
        return $this->span->getStartTime();
    }

    public function getTotalRecordedLinks(): int
    {
        return 0;
    }

    public function getTotalRecordedEvents(): int
    {
        return 0;
    }

    /**
     * @inheritDoc
     */
    public function isRecording(): bool
    {
        return !$this->hasEnded();
    }

    private static function _setAttribute(SpanData $span, string $key, $value): void
    {
        if ($value === null && isset($span->meta[$key])) {
            unset($span->meta[$key]);
        } elseif ($value === null && isset($span->metrics[$key])) {
            unset($span->metrics[$key]);
        } elseif (strpos($key, '_dd.p.') === 0) {
            $distributedKey = substr($key, 6); // strlen('_dd.p.') === 6
            \DDTrace\add_distributed_tag($distributedKey, $value);
        } elseif (is_float($value)
            || is_int($value)
            || (is_array($value) && count($value) > 0 && is_numeric($value[0]))) { // Note: Assumes attribute with primitive, homogeneous array values
            $span->metrics[$key] = $value;
        } else {
            $span->meta[$key] = $value;
        }
    }

    private static function _setAttributes(SpanData $span, iterable $attributes): void
    {
        foreach ($attributes as $key => $value) {
            self::_setAttribute($span, $key, $value);
        }
    }

    /**
     * @inheritDoc
     */
    public function setAttribute(string $key, $value): SpanInterface
    {
        if (!$this->hasEnded()) {
            self::_setAttribute($this->span, $key, $value);
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setAttributes(iterable $attributes): SpanInterface
    {
        if (!$this->hasEnded()) {
            foreach ($attributes as $key => $value) {
                $this->setAttribute($key, $value);
            }
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
        if (!$this->hasEnded()) {
            $this->span->meta[Tag::ERROR_MSG] = $exception->getMessage();
            $this->span->meta[Tag::ERROR_TYPE] = get_class($exception);
            $this->span->meta[Tag::ERROR_STACK] = $exception->getTraceAsString();
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function updateName(string $name): SpanInterface
    {
        // OTel.name => DD.resource
        if (!$this->hasEnded()) {
            $this->span->resource = $name;
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setStatus(string $code, string $description = null): SpanInterface
    {
        if ($this->hasEnded()) {
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
            unset($this->span->meta[Tag::ERROR_TYPE]);
            unset($this->span->meta[Tag::ERROR_STACK]);
        }

        $this->status = StatusData::create($code, $description);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function end(int $endEpochNanos = null): void
    {
        if ($this->hasEnded()) {
            return;
        }

        $this->endOTelSpan($endEpochNanos);

        switch_stack($this->span);
        close_span($endEpochNanos !== null ? $endEpochNanos / 1000000000 : 0);
    }

    public function endOTelSpan(int $endEpochNanos = null): void
    {
        if ($this->hasEnded()) {
            return;
        }

        if (empty($this->span->name)) { // Honor set operation name
            $this->span->name = Convention::defaultOperationName($this->span);
        }

        // After closing the span, the context won't change, but would otherwise be lost
        $this->context = API\SpanContext::create(
            $this->context->getTraceId(),
            $this->context->getSpanId(),
            $this->context->getTraceFlags(),
            $this->context->getTraceState()
        );

        $this->spanProcessor->onEnd($this);
    }

    public function getResource(): ResourceInfo
    {
        return $this->resource;
    }

    /**
     * @internal
     * @return SpanData
     */
    public function getDDSpan(): SpanData
    {
        return $this->span;
    }
}
