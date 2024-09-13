<?php

declare(strict_types=1);

namespace OpenTelemetry\SDK\Trace;

use DDTrace\SpanData;
use DDTrace\SpanLink;
use DDTrace\SpanEvent;
use DDTrace\ExceptionSpanEvent;
use DDTrace\Tag;
use DDTrace\OpenTelemetry\Convention;
use DDTrace\Util\ObjectKVStore;
use OpenTelemetry\API\Trace as API;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Attribute\AttributesBuilderInterface;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Common\Time\ClockFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use Throwable;
use function DDTrace\close_span;
use function DDTrace\switch_stack;
use function DDTrace\Internal\add_span_flag;

final class Span extends API\Span implements ReadWriteSpanInterface
{
    private SpanData $span;

    /** @readonly */
    private API\SpanContextInterface $context;

    /** @readonly */
    private API\SpanContextInterface $parentSpanContext;

    /** @readonly */
    private SpanProcessorInterface $spanProcessor;

    /**
     * @readonly
     *
     * @var list<LinkInterface>
     */
    private array $links;

    /** @readonly */
    private int $totalRecordedLinks;

    /**
     * @readonly
     *
     * @var list<EventInterface>
     */
    private array $events;

     /** @readonly */
    private int $totalRecordedEvents;

    /** @readonly */
    private int $kind;

    /** @readonly */
    private ResourceInfo $resource;

    /** @readonly */
    private InstrumentationScopeInterface $instrumentationScope;

    private StatusDataInterface $status;

    private string $operationNameConvention = "";

    private function __construct(
        SpanData $span,
        API\SpanContextInterface $context,
        InstrumentationScopeInterface $instrumentationScope,
        int $kind,
        API\SpanContextInterface $parentSpanContext,
        SpanProcessorInterface $spanProcessor,
        ResourceInfo $resource,
        array $links = [],
        int $totalRecordedLinks = 0,
        array $events = [],
        bool $isRemapped = true
    ) {
        $this->span = $span;
        $this->context = $context;
        $this->instrumentationScope = $instrumentationScope;
        $this->kind = $kind;
        $this->parentSpanContext = $parentSpanContext;
        $this->spanProcessor = $spanProcessor;
        $this->resource = $resource;
        $this->links = $links;
        $this->totalRecordedLinks = $totalRecordedLinks;
        $this->events = $events;

        $this->status = StatusData::unset();

        if ($isRemapped || empty($span->name)) {
            // Since the span was created using the OTel API, it doesn't have an operation name*
            // *: This is a bit false. For instance, a span created using the OTel API and DD_TRACE_GENERATE_ROOT_SPAN=0
            // under a cli (e.g., phpunit) process would have a default operation name set (e.g., phpunit). This is
            // done in serializer.c:ddtrace_set_root_span_properties (as of v0.92.0)
            $span->name = $this->operationNameConvention = Convention::defaultOperationName($span);
        }

        // Set the span links and events
        if ($isRemapped) {
            // At initialization time (now), only set the links if the span was created using the OTel API
            // Otherwise, the links were already set in DD's OpenTelemetry\Context\Context
            foreach ($links as $link) {
                /** @var LinkInterface $link */
                $linkContext = $link->getSpanContext();
                $span->links[] = $this->createAndSaveSpanLink($linkContext, $link->getAttributes()->toArray(), $link);
            }

            foreach ($events as $event) {
                /** @var EventInterface $event */

                $spanEvent = new SpanEvent(
                    $event->getName(),
                    $event->getAttributes()->toArray(),
                    $event->getEpochNanos()
                );

                // Save the event
                ObjectKVStore::put($spanEvent, "event", $event);
                $span->events[] = $spanEvent;
            }
        }
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
        array $attributes,
        array $links,
        int $totalRecordedLinks,
        array $events,
        bool $isRemapped = true // Answers the question "Was the span created using the OTel API?"
    ): self {
        self::_setAttributes($span, $attributes);

        $resourceAttributes = $resource->getAttributes()->toArray();
        self::_setAttributes($span, $resourceAttributes);

        // Mark the span as created by OpenTelemetry
        add_span_flag($span, \DDTrace\Internal\SPAN_FLAG_OPENTELEMETRY);

        $OTelSpan = new self(
            $span,
            $context,
            $instrumentationScope,
            $kind,
            $parentSpan->getContext(),
            $spanProcessor,
            $resource,
            $links,
            $totalRecordedLinks,
            $events,
            $isRemapped
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

        $this->updateSpanLinks();
        $this->updateSpanEvents();

        if (in_array('addLink', get_class_methods(SpanInterface::class))) {
            return new ImmutableSpan(
                $this,
                $this->getName(),
                $this->links,
                $this->events,
                Attributes::create(array_merge($this->span->meta, $this->span->metrics)),
                $this->totalRecordedEvents,
                $this->totalRecordedLinks,
                StatusData::create($this->status->getCode(), $this->status->getDescription()),
                $hasEnded ? $this->span->getStartTime() + $this->span->getDuration() : 0,
                $this->hasEnded(),
            );
        } else {
            return new ImmutableSpan(
                $this,
                $this->getName(),
                $this->links,
                $this->events,
                Attributes::create(array_merge($this->span->meta, $this->span->metrics)),
                $this->totalRecordedEvents,
                StatusData::create($this->status->getCode(), $this->status->getDescription()),
                $hasEnded ? $this->span->getStartTime() + $this->span->getDuration() : 0,
                $this->hasEnded(),
            );
        }
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
        return $this->totalRecordedLinks;
    }

    public function getTotalRecordedEvents(): int
    {
        return $this->totalRecordedEvents;
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
        if ($value === null) {
            unset($span->meta[$key]);
            unset($span->metrics[$key]);
        } elseif ($key[0] === '_' && \strncmp($key, '_dd.p.', 6) === 0) {
            $distributedKey = \substr($key, 6); // strlen('_dd.p.') === 6
            \DDTrace\add_distributed_tag($distributedKey, $value);
        } elseif (\is_float($value)
            || \is_int($value)
            || (\is_array($value) && \count($value) > 0 && \is_numeric($value[0]))) { // Note: Assumes attribute with primitive, homogeneous array values
            $span->metrics[$key] = $value;
        } elseif ($key === 'service.name') {
            $span->service = $value;
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

    private function updateConvention(): void
    {
        if ($this->span->name === $this->operationNameConvention) {
            $this->span->name = $this->operationNameConvention = Convention::defaultOperationName($this->span);
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

        $this->updateConvention();

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

        $this->updateConvention();

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function addLink(SpanContextInterface $context, iterable $attributes = []): SpanInterface
    {
        if ($this->hasEnded() || !$context->isValid()) {
            return $this;
        }

        $this->span->links[] = $this->createAndSaveSpanLink($context, $attributes);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function addEvent(string $name, iterable $attributes = [], int $timestamp = null): SpanInterface
    {
        if (!$this->hasEnded()) {
            $this->span->events[] = new SpanEvent(
                $name,
                $attributes,
                $timestamp ?? (int)(microtime(true) * 1e9)
            );
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function recordException(Throwable $exception, iterable $attributes = []): SpanInterface
    {
        if (!$this->hasEnded()) {
            // Update span metadata based on exception stack
            $this->setAttribute(Tag::ERROR_STACK, \DDTrace\get_sanitized_exception_trace($exception));

            $this->span->events[] = new ExceptionSpanEvent(
                $exception,
                $attributes
            );
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
        $this->spanProcessor->onEnd($this);
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

    private function updateSpanLinks()
    {
        // Important: Span Links are supposed immutable
        $datadogSpanLinks = $this->span->links;

        $otel = [];
        foreach ($datadogSpanLinks as $datadogSpanLink) {
            // Check if the link relationship exists
            $link = ObjectKVStore::get($datadogSpanLink, "link");
            if ($link === null) {
                // Create the link
                $link = new Link(
                    API\SpanContext::create(
                        $datadogSpanLink->traceId,
                        $datadogSpanLink->spanId,
                        $this->context->getTraceFlags(),
                        new API\TraceState($datadogSpanLink->traceState ?? null)
                    ),
                    Attributes::create($datadogSpanLink->attributes ?? [])
                );

                // Save the link
                ObjectKVStore::put($datadogSpanLink, "link", $link);
            }
            $otel[] = $link;
        }

        // Update the links
        $this->links = $otel;
        $this->totalRecordedLinks = count($otel);
    }

    private function updateSpanEvents()
    {
        $datadogSpanEvents = $this->span->events;
        $this->span->meta["events"] = count($this->events);

        $otel = [];
        foreach ($datadogSpanEvents as $datadogSpanEvent) {
            $exceptionAttributes = [];
            $event = ObjectKVStore::get($datadogSpanEvent, "event");
            if ($event === null) {
                if ($datadogSpanEvent instanceof ExceptionSpanEvent) {
                    // Standardized exception attributes
                    $exceptionAttributes = [
                        'exception.message' => $attributes['exception.message'] ?? $datadogSpanEvent->exception->getMessage(),
                        'exception.type' => $attributes['exception.type'] ?? get_class($datadogSpanEvent->exception),
                        'exception.stacktrace' => $attributes['exception.stacktrace'] ?? \DDTrace\get_sanitized_exception_trace($datadogSpanEvent->exception)
                    ];
                }
                $event = new Event(
                    $datadogSpanEvent->name,
                    (int)$datadogSpanEvent->timestamp,
                    Attributes::create(array_merge($exceptionAttributes, \is_array($datadogSpanEvent->attributes) ? $datadogSpanEvent->attributes : iterator_to_array($datadogSpanEvent->attributes)))
                );

                // Save the event
                ObjectKVStore::put($datadogSpanEvent, "event", $event);
            }
            $otel[] = $event;
        }

        // Update the events
        $this->events = $otel;
        $this->totalRecordedEvents = count($otel);
    }

    private function createAndSaveSpanLink(SpanContextInterface $context, iterable $attributes = [], LinkInterface $link = null)
    {
        $spanLink = new SpanLink();
        $spanLink->traceId = $context->getTraceId();
        $spanLink->spanId = $context->getSpanId();
        $spanLink->traceState = (string)$context->getTraceState(); // __toString()
        $spanLink->attributes = $attributes;
        $spanLink->droppedAttributesCount = 0; // Attributes limit aren't supported/meaningful in DD

        // Save the link
        if (is_null($link)) {
            $link = new Link($context, Attributes::create($attributes));
        }
        ObjectKVStore::put($spanLink, "link", $link);

        return $spanLink;
    }
}
