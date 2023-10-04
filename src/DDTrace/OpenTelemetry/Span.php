<?php

declare(strict_types=1);

namespace DDTrace\OpenTelemetry\SDK\Trace;

use DDTrace\Propagator;
use DDTrace\SpanData;
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
use function DDTrace\trace_id;

final class Span extends API\Span implements ReadWriteSpanInterface
{
    private SpanData $span;

    /** @readonly */
    private API\SpanContextInterface $context;

    /** @readonly */
    private API\SpanContextInterface $parentSpanContext;

    /**
     * @readonly
     *
     * @var list<LinkInterface>
     */
    private array $links;

    /** @readonly */
    private int $totalRecordedLinks;

    /** @readonly */
    private int $kind;

    /** @readonly */
    private InstrumentationScopeInterface $instrumentationScope;

    /** @readonly */
    private int $startEpochNanos;

    /** @var non-empty-string */
    private string $name;

    /** @var list<EventInterface> */
    private array $events = [];

    private AttributesBuilderInterface $attributesBuilder;
    private int $totalRecordedEvents = 0;
    private StatusDataInterface $status;
    private int $endEpochNanos = 0;
    private bool $hasEnded = false;

    private function __construct(
        SpanData $span,
        API\SpanContextInterface $context,
        InstrumentationScopeInterface $instrumentationScope,
        int $kind,
        API\SpanContextInterface $parentSpanContext,
        AttributesBuilderInterface $attributesBuilder,
        array $links,
        int $totalRecordedLinks,
        int $startEpochNanos
    ) {
        $this->span = $span;
        $this->context = $context;
        $this->instrumentationScope = $instrumentationScope;
        $this->kind = $kind;
        $this->parentSpanContext = $parentSpanContext;
        $this->attributesBuilder = $attributesBuilder;
        $this->links = $links;
        $this->totalRecordedLinks = $totalRecordedLinks;
        $this->startEpochNanos = $startEpochNanos;

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
        ContextInterface $parentContext,
        AttributesBuilderInterface $attributesBuilder,
        array $links,
        int $totalRecordedLinks,
        int $startEpochNanos
    ): self {
        $OTelSpan = new self(
            $span,
            $context,
            $instrumentationScope,
            $kind,
            $parentContext,
            $attributesBuilder,
            $links,
            $totalRecordedLinks,
            $startEpochNanos
        );

        // TODO: Span Processors are future work

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
            $this->links, // TODO: Handle Span Links
            $this->events, // TODO: Handle Span Events
            Attributes::create($this->span->meta), // TODO: See if it handles 'nested' attributes correctly
            0,
            new StatusData($this->statusCode, $this->description),
            $this->hasEnded ? $this->getDuration() : 0,
            $this->hasEnded
        );
    }

    /**
     * @inheritDoc
     */
    public function getDuration(): int
    {
        return $this->span->getDuration();
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

    /**
     * @inheritDoc
     */
    public function isRecording(): bool
    {
        return !$this->hasEnded;
    }

    /**
     * @inheritDoc
     */
    public function setAttribute(string $key, $value): SpanInterface
    {
        if (!$this->hasEnded) {
            $this->span->meta[$key] = $value;
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setAttributes(iterable $attributes): SpanInterface
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
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

        if ($this->statusCode === API\StatusCode::STATUS_UNSET && $code === API\StatusCode::STATUS_ERROR) {
            $this->statusCode = $code;
            $this->description = $description;
            $this->span->meta[Tag::ERROR_MSG] = $description;
        } elseif ($this->statusCode === API\StatusCode::STATUS_ERROR && $code === API\StatusCode::STATUS_OK) {
            $this->statusCode = $code;
            $this->description = $description;
            unset($this->span->meta[Tag::ERROR_MSG]);
            unset($this->span->meta[Tag::ERROR_TYPE]);
            unset($this->span->meta[Tag::ERROR_STACK]);
        }

        // TODO: Look into this

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

        // TODO: Actually check if the span was closed (change extension to return a boolean?)
        close_span($endEpochNanos !== null ? $endEpochNanos / 1000000000 : 0);
        $this->hasEnded = true;
    }
}
