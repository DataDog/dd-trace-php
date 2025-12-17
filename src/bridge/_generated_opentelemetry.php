<?php
namespace OpenTelemetry\Context {
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Util\ObjectKVStore;
use OpenTelemetry\API\Trace as API;
use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Baggage\BaggageBuilder;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Attribute\AttributesFactory;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace as SDK;
use OpenTelemetry\SDK\Trace\SpanProcessor\NoopSpanProcessor;
use function DDTrace\active_span;
use function DDTrace\generate_distributed_tracing_headers;
use function spl_object_id;
/**
 * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/context/README.md#context
 */
final class Context implements ContextInterface
{
    /** @var ContextStorageInterface&ExecutionContextAwareInterface */
    private static ContextStorageInterface $storage;
    /** @var string $storageClass */
    private static string $storageClass = '';
    // Optimization for spans to avoid copying the context array.
    private static ContextKeyInterface $spanContextKey;
    private static ContextKeyInterface $baggageContextKey;
    private ?object $span = null;
    /** @var array<int, mixed> */
    private array $context = [];
    /** @var array<int, ContextKeyInterface> */
    private array $contextKeys = [];
    private function __construct()
    {
        self::$spanContextKey = ContextKeys::span();
        self::$baggageContextKey = ContextKeys::baggage();
    }
    public static function createKey(string $key): ContextKeyInterface
    {
        return new ContextKey($key);
    }
    /**
     * @param ContextStorageInterface&ExecutionContextAwareInterface $storage
     */
    public static function setStorage(ContextStorageInterface $storage): void
    {
        self::$storage = $storage;
    }
    /**
     * @return ContextStorageInterface&ExecutionContextAwareInterface
     */
    public static function storage(): ContextStorageInterface
    {
        if (self::$storageClass === '') {
            self::$storageClass = class_exists('OpenTelemetry\Context\FiberBoundContextStorageExecutionAwareBC') ? 'OpenTelemetry\Context\FiberBoundContextStorageExecutionAwareBC' : 'OpenTelemetry\Context\ContextStorage';
        }
        return self::$storage ??= new self::$storageClass();
    }
    /**
     * @param ContextInterface|false|null $context
     *
     * @internal OpenTelemetry
     */
    public static function resolve($context, ?ContextStorageInterface $contextStorage = null): ContextInterface
    {
        $spanFromContext = API\Span::fromContext(self::storage()->current());
        if ($spanFromContext instanceof SDK\Span) {
            $ddSpanFromContext = $spanFromContext->getDDSpan();
            self::deactivateEndedParents($ddSpanFromContext);
        }
        self::activateParent(active_span());
        return $context ?? ($contextStorage ?? self::storage())->current() ?: self::getRoot();
    }
    /**
     * @internal
     */
    public static function getRoot(): ContextInterface
    {
        static $empty;
        return $empty ??= new self();
    }
    private static function getDDInstrumentationScope(): InstrumentationScopeInterface
    {
        static $instrumentationScope;
        return $instrumentationScope ??= (new InstrumentationScopeFactory(new AttributesFactory()))->create('datadog');
    }
    public static function getCurrent(): ContextInterface
    {
        $spanFromContext = API\Span::fromContext(self::storage()->current());
        if ($spanFromContext instanceof SDK\Span) {
            $ddSpanFromContext = $spanFromContext->getDDSpan();
            self::deactivateEndedParents($ddSpanFromContext);
        }
        return self::activateParent(active_span());
    }
    private static function deactivateEndedParents(?SpanData $currentSpan)
    {
        if ($currentSpan === null) {
            // Terminal condition - root span
            return;
        }
        if ($currentSpan->getDuration() === 0) {
            // The span is still active, so its parents are still active
            return;
        }
        // The dd span is ended, so end the OTel span
        /** @var SDK\Span $OTelCurrentSpan */
        $OTelCurrentSpan = ObjectKVStore::get($currentSpan, 'otel_span');
        // Note: SDK\Span::startSpan() associates the DDTrace span with the OTel span when it is created
        if ($OTelCurrentSpan !== null) {
            $OTelCurrentSpan->endOTelSpan();
        }
        // End the parent spans
        self::deactivateEndedParents($currentSpan->parent);
    }
    private static function convertDDSpanKindToOtel(string $spanKind)
    {
        switch ($spanKind) {
            case Tag::SPAN_KIND_VALUE_CLIENT:
                return API\SpanKind::KIND_CLIENT;
            case Tag::SPAN_KIND_VALUE_SERVER:
                return API\SpanKind::KIND_SERVER;
            case Tag::SPAN_KIND_VALUE_PRODUCER:
                return API\SpanKind::KIND_PRODUCER;
            case Tag::SPAN_KIND_VALUE_CONSUMER:
                return API\SpanKind::KIND_CONSUMER;
            case Tag::SPAN_KIND_VALUE_INTERNAL:
            default:
                return API\SpanKind::KIND_INTERNAL;
        }
    }
    private static function activateParent(?SpanData $currentSpan): ContextInterface
    {
        if ($currentSpan === null) {
            // Terminal condition - root span
            return self::storage()->current();
        }
        /** @var SDK\Span $OTelCurrentSpan */
        $OTelCurrentSpan = ObjectKVStore::get($currentSpan, 'otel_span');
        // Note: SDK\Span::startSpan() associates the DDTrace span with the OTel span when it is created
        if ($OTelCurrentSpan !== null) {
            // If the current span has been activated, nothing to do, trigger backtalk
            // Return the context associated with the current span
            if (ObjectKVStore::get($currentSpan, 'ddtrace_scope_activated')) {
                return self::storage()->current()->with(self::$spanContextKey, $OTelCurrentSpan);
            } else {
                return self::storage()->current();
            }
        }
        $parentContext = self::activateParent($currentSpan->parent);
        // Activates the ancestors first
        // Create a new span from the current span
        $currentSpanId = $currentSpan->hexId();
        $currentTraceId = \DDTrace\root_span()->traceId;
        $traceContext = generate_distributed_tracing_headers(['tracecontext']);
        $traceFlags = isset($traceContext['traceparent']) ? substr($traceContext['traceparent'], -2) === '01' ? API\TraceFlags::SAMPLED : API\TraceFlags::DEFAULT : null;
        $traceState = new API\TraceState($traceContext['tracestate'] ?? null);
        // Check for span links
        $links = [];
        foreach ($currentSpan->links as $spanLink) {
            $linkSpanContext = API\SpanContext::create($spanLink->traceId, $spanLink->spanId, API\TraceFlags::DEFAULT, new API\TraceState($spanLink->traceState ?? null));
            $links[] = new SDK\Link($linkSpanContext, Attributes::create($spanLink->attributes ?? []));
        }
        // Check for span events
        $events = [];
        foreach ($currentSpan->events as $spanEvent) {
            $events[] = new SDK\Event($spanEvent->name, (int) $spanEvent->timestamp, Attributes::create((array) $spanEvent->attributes ?? []));
        }
        $OTelCurrentSpan = SDK\Span::startSpan(
            $currentSpan,
            API\SpanContext::create($currentTraceId, $currentSpanId, $traceFlags, $traceState),
            // $context
            self::getDDInstrumentationScope(),
            // $instrumentationScope
            isset($currentSpan->meta[Tag::SPAN_KIND]) ? self::convertDDSpanKindToOtel($currentSpan->meta[Tag::SPAN_KIND]) : API\SpanKind::KIND_INTERNAL,
            // $kind
            API\Span::fromContext($parentContext),
            // $parentSpan (TODO: Handle null parent span) ?
            $parentContext,
            // $parentContext
            NoopSpanProcessor::getInstance(),
            // $spanProcessor
            ResourceInfoFactory::emptyResource(),
            // $resource
            [],
            // $attributesBuilder
            $links,
            // $links
            count($links),
            // $totalRecordedLinks
            $events,
            //$events
            false
        );
        ObjectKVStore::put($currentSpan, 'otel_span', $OTelCurrentSpan);
        $currentContext = $parentContext->with(self::$spanContextKey, $OTelCurrentSpan);
        // Sets the current span in the context
        ObjectKVStore::put($currentSpan, 'ddtrace_scope_activated', true);
        self::storage()->attach($currentContext);
        // TODO: Handle Detach
        return $currentContext;
    }
    public function activate(): ScopeInterface
    {
        if ($this->span instanceof SDK\Span) {
            ObjectKVStore::put($this->span->getDDSpan(), 'ddtrace_scope_activated', true);
        }
        $scope = self::storage()->attach($this);
        return $scope;
    }
    public function withContextValue(ImplicitContextKeyedInterface $value): ContextInterface
    {
        return $value->storeInContext($this);
    }
    public function with(ContextKeyInterface $key, $value): self
    {
        if ($this->get($key) === $value) {
            return $this;
        }
        $self = clone $this;
        if ($key === self::$spanContextKey) {
            $self->span = $value;
            // @phan-suppress-current-line PhanTypeMismatchPropertyReal
            return $self;
        }
        $id = spl_object_id($key);
        if ($key === self::$baggageContextKey) {
            if ($this->span instanceof SDK\Span) {
                $currentDdSpan = $self->span ? $self->span->getDDSpan() : null;
                if ($currentDdSpan !== null) {
                    $currentDdSpan->baggage = [];
                    foreach ($value->getAll() as $baggageKey => $baggageEntry) {
                        $currentDdSpan->baggage[$baggageKey] = $baggageEntry->getValue();
                    }
                    return $self;
                }
            }
        }
        if ($value !== null) {
            $self->context[$id] = $value;
            $self->contextKeys[$id] ??= $key;
        } else {
            unset($self->context[$id], $self->contextKeys[$id]);
        }
        return $self;
    }
    public function get(ContextKeyInterface $key)
    {
        if ($key === self::$spanContextKey) {
            /** @psalm-suppress InvalidReturnStatement */
            return $this->span;
        }
        if ($key === self::$baggageContextKey) {
            if ($this->span instanceof SDK\Span) {
                $currentDdSpan = $this->span ? $this->span->getDDSpan() : null;
                if ($currentDdSpan) {
                    $baggageBuilder = new BaggageBuilder();
                    foreach ($currentDdSpan->baggage as $baggageKey => $baggageValue) {
                        $baggageBuilder->set($baggageKey, $baggageValue);
                    }
                    return $baggageBuilder->build();
                }
            }
        }
        return $this->context[spl_object_id($key)] ?? null;
    }
}
}

namespace DDTrace\OpenTelemetry {
use DDTrace\SpanData;
use DDTrace\Tag;
// Operation Name Conventions
class Convention
{
    public static function defaultOperationName(SpanData $span): string
    {
        $meta = $span->meta;
        $spanKind = $meta[Tag::SPAN_KIND] ?? null;
        switch (true) {
            case isset($meta['http.request.method']) && $spanKind === Tag::SPAN_KIND_VALUE_SERVER:
                // HTTP Server
                return 'http.server.request';
            case isset($meta['http.request.method']) && $spanKind === Tag::SPAN_KIND_VALUE_CLIENT:
                // HTTP Client
                return 'http.client.request';
            case isset($meta['db.system']) && $spanKind === Tag::SPAN_KIND_VALUE_CLIENT:
                // Database
                return strtolower($meta['db.system']) . '.query';
            case isset($meta['messaging.system'], $meta['messaging.operation']) && in_array($spanKind, [Tag::SPAN_KIND_VALUE_CONSUMER, Tag::SPAN_KIND_VALUE_PRODUCER, Tag::SPAN_KIND_VALUE_SERVER, Tag::SPAN_KIND_VALUE_CLIENT]):
                return strtolower($meta['messaging.system']) . '.' . strtolower($meta['messaging.operation']);
            case isset($meta['rpc.system']) && $meta['rpc.system'] === 'aws-api' && $spanKind === Tag::SPAN_KIND_VALUE_CLIENT:
                // AWS Client
                return isset($meta['rpc.service']) ? 'aws.' . strtolower($meta['rpc.service']) . '.request' : 'aws.client.request';
            case isset($meta['rpc.system']) && $spanKind === Tag::SPAN_KIND_VALUE_CLIENT:
                // RPC Client
                return strtolower($meta['rpc.system']) . '.client.request';
            case isset($meta['rpc.system']) && $spanKind === Tag::SPAN_KIND_VALUE_SERVER:
                // RPC Server
                return strtolower($meta['rpc.system']) . '.server.request';
            case isset($meta['faas.trigger']) && $spanKind === Tag::SPAN_KIND_VALUE_SERVER:
                // FaaS Server
                return strtolower($meta['faas.trigger']) . '.invoke';
            case isset($meta['faas.invoked_provider'], $meta['faas.invoked_name']) && $spanKind === Tag::SPAN_KIND_VALUE_CLIENT:
                // FaaS Client
                return strtolower($meta['faas.invoked_provider']) . '.' . strtolower($meta['faas.invoked_name']) . '.invoke';
            case isset($meta['graphql.operation.type']):
                return 'graphql.server.request';
            case $spanKind === Tag::SPAN_KIND_VALUE_SERVER:
            // Generic
            case $spanKind === Tag::SPAN_KIND_VALUE_CLIENT:
                return isset($meta['network.protocol.name']) ? strtolower($meta['network.protocol.name']) . ".{$spanKind}.request" : "{$spanKind}.request";
            case !empty($spanKind):
                return $spanKind;
            default:
                // If all else fails, we still shouldn't use the resource name
                return '';
        }
    }
}
}

namespace DDTrace\OpenTelemetry\API\Trace {
use DDTrace\OpenTelemetry\SDK\Trace\Span;
use DDTrace\SpanData;
use OpenTelemetry\API\Trace as API;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanContextValidator;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TraceState;
use OpenTelemetry\API\Trace\TraceStateInterface;
use function DDTrace\generate_distributed_tracing_headers;
final class SpanContext implements SpanContextInterface
{
    /** @var SpanData */
    private $span;
    private bool $sampled;
    private bool $remote;
    private ?string $traceId;
    private ?string $spanId;
    private bool $isValid = true;
    private ?string $currentTracestateString = null;
    private ?TraceStateInterface $currentTracestateInstance = null;
    private function __construct(SpanData $span, bool $sampled, bool $remote, ?string $traceId = null, ?string $spanId = null)
    {
        $this->span = $span;
        $this->sampled = $sampled;
        $this->remote = $remote;
        $this->traceId = $traceId ?: \DDTrace\root_span()->traceId;
        $this->spanId = $spanId ?: $this->span->hexId();
        // TraceId must be exactly 16 bytes (32 chars) and at least one non-zero byte
        // SpanId must be exactly 8 bytes (16 chars) and at least one non-zero byte
        if (!SpanContextValidator::isValidTraceId($this->traceId) || !SpanContextValidator::isValidSpanId($this->spanId)) {
            $this->traceId = SpanContextValidator::INVALID_TRACE;
            $this->spanId = SpanContextValidator::INVALID_SPAN;
            $this->isValid = false;
        }
    }
    /**
     * @inheritDoc
     */
    public function getTraceId(): string
    {
        return $this->traceId;
    }
    public function getTraceIdBinary(): string
    {
        return hex2bin($this->getTraceId());
    }
    /**
     * @inheritDoc
     */
    public function getSpanId(): string
    {
        return $this->spanId;
    }
    public function getSpanIdBinary(): string
    {
        return hex2bin($this->getSpanId());
    }
    public function getTraceState(): ?TraceStateInterface
    {
        $current = \DDTrace\active_stack();
        if ($current !== $this->span->stack) {
            \DDTrace\switch_stack($this->span);
            $traceContext = generate_distributed_tracing_headers(['tracecontext']);
            \DDTrace\switch_stack($current);
        } else {
            $traceContext = generate_distributed_tracing_headers(['tracecontext']);
        }
        $newTracestate = $traceContext['tracestate'] ?? null;
        if ($this->currentTracestateInstance === null || $this->currentTracestateString !== $newTracestate) {
            $this->currentTracestateString = $newTracestate;
            $this->currentTracestateInstance = new TraceState($newTracestate);
        }
        return $this->currentTracestateInstance;
    }
    public function isSampled(): bool
    {
        return $this->sampled;
    }
    public function isValid(): bool
    {
        return $this->isValid;
    }
    public function isRemote(): bool
    {
        return $this->remote;
    }
    public function getTraceFlags(): int
    {
        return $this->sampled ? TraceFlags::SAMPLED : TraceFlags::DEFAULT;
    }
    /** @inheritDoc */
    public static function createFromRemoteParent(string $traceId, string $spanId, int $traceFlags = TraceFlags::DEFAULT, ?TraceStateInterface $traceState = null): SpanContextInterface
    {
        return API\SpanContext::createFromRemoteParent($traceId, $spanId, $traceFlags, $traceState);
    }
    /** @inheritDoc */
    public static function create(string $traceId, string $spanId, int $traceFlags = TraceFlags::DEFAULT, ?TraceStateInterface $traceState = null): SpanContextInterface
    {
        return API\SpanContext::create($traceId, $spanId, $traceFlags, $traceState);
    }
    /** @inheritDoc */
    public static function getInvalid(): SpanContextInterface
    {
        return API\SpanContext::getInvalid();
    }
    public static function createFromLocalSpan(SpanData $span, bool $sampled, ?string $traceId = null, ?string $spanId = null)
    {
        return new self($span, $sampled, false, $traceId, $spanId);
    }
}
}

namespace OpenTelemetry\SDK\Trace {
use DDTrace\SpanData;
use DDTrace\SpanLink;
use DDTrace\SpanEvent;
use DDTrace\ExceptionSpanEvent;
use DDTrace\Tag;
use DDTrace\OpenTelemetry\Convention;
use DDTrace\Util\ObjectKVStore;
use OpenTelemetry\API\Trace as API;
use OpenTelemetry\SDK\Trace\LinkInterface;
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
    private function __construct(SpanData $span, API\SpanContextInterface $context, InstrumentationScopeInterface $instrumentationScope, int $kind, API\SpanContextInterface $parentSpanContext, SpanProcessorInterface $spanProcessor, ResourceInfo $resource, array $links = [], int $totalRecordedLinks = 0, array $events = [], bool $isRemapped = true)
    {
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
                $spanEvent = new SpanEvent($event->getName(), $event->getAttributes()->toArray(), $event->getEpochNanos());
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
    public static function startSpan(SpanData $span, API\SpanContextInterface $context, InstrumentationScopeInterface $instrumentationScope, int $kind, API\SpanInterface $parentSpan, ContextInterface $parentContext, SpanProcessorInterface $spanProcessor, ResourceInfo $resource, array $attributes, array $links, int $totalRecordedLinks, array $events, bool $isRemapped = true): self
    {
        self::_setAttributes($span, $attributes);
        $resourceAttributes = $resource->getAttributes()->toArray();
        self::_setAttributes($span, $resourceAttributes);
        // Mark the span as created by OpenTelemetry
        add_span_flag($span, \DDTrace\Internal\SPAN_FLAG_OPENTELEMETRY);
        $OTelSpan = new self($span, $context, $instrumentationScope, $kind, $parentSpan->getContext(), $spanProcessor, $resource, $links, $totalRecordedLinks, $events, $isRemapped);
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
        if (method_exists(SpanInterface::class, 'addLink')) {
            // v1.1 backward compatibility: totalRecordedLinks parameter added
            return new ImmutableSpan($this, $this->getName(), $this->links, $this->events, Attributes::create(array_merge($this->span->meta, $this->span->metrics)), $this->totalRecordedEvents, $this->totalRecordedLinks, StatusData::create($this->status->getCode(), $this->status->getDescription()), $hasEnded ? $this->span->getStartTime() + $this->span->getDuration() : 0, $this->hasEnded());
        } else {
            return new ImmutableSpan($this, $this->getName(), $this->links, $this->events, Attributes::create(array_merge($this->span->meta, $this->span->metrics)), $this->totalRecordedEvents, StatusData::create($this->status->getCode(), $this->status->getDescription()), $hasEnded ? $this->span->getStartTime() + $this->span->getDuration() : 0, $this->hasEnded());
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
        return $this->span->meta[$key] ?? $this->span->metrics[$key] ?? null;
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
            $distributedKey = \substr($key, 6);
            // strlen('_dd.p.') === 6
            \DDTrace\add_distributed_tag($distributedKey, $value);
        } elseif (\is_float($value) || \is_int($value) || \is_array($value) && \count($value) > 0 && \is_numeric($value[0])) {
            // Note: Assumes attribute with primitive, homogeneous array values
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
    public function addEvent(string $name, iterable $attributes = [], $timestamp = null): SpanInterface
    {
        if (!$this->hasEnded()) {
            $this->span->events[] = new SpanEvent($name, $attributes, $timestamp ?? (int) (microtime(true) * 1000000000.0));
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
            $this->span->events[] = new ExceptionSpanEvent($exception, $attributes);
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
    public function setStatus(string $code, ?string $description = null): SpanInterface
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
    public function end(?int $endEpochNanos = null): void
    {
        if ($this->hasEnded()) {
            return;
        }
        $this->endOTelSpan($endEpochNanos);
        switch_stack($this->span);
        close_span($endEpochNanos !== null ? $endEpochNanos / 1000000000 : 0);
        $this->spanProcessor->onEnd($this);
    }
    public function endOTelSpan(?int $endEpochNanos = null): void
    {
        if ($this->hasEnded()) {
            return;
        }
        if (empty($this->span->name)) {
            // Honor set operation name
            $this->span->name = Convention::defaultOperationName($this->span);
        }
        // After closing the span, the context won't change, but would otherwise be lost
        $this->context = API\SpanContext::create($this->context->getTraceId(), $this->context->getSpanId(), $this->context->getTraceFlags(), $this->context->getTraceState());
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
                $link = new Link(API\SpanContext::create($datadogSpanLink->traceId, $datadogSpanLink->spanId, $this->context->getTraceFlags(), new API\TraceState($datadogSpanLink->traceState ?? null)), Attributes::create($datadogSpanLink->attributes ?? []));
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
                    $exceptionAttributes = ['exception.message' => $attributes['exception.message'] ?? $datadogSpanEvent->exception->getMessage(), 'exception.type' => $attributes['exception.type'] ?? get_class($datadogSpanEvent->exception), 'exception.stacktrace' => $attributes['exception.stacktrace'] ?? \DDTrace\get_sanitized_exception_trace($datadogSpanEvent->exception)];
                }
                $event = new Event($datadogSpanEvent->name, (int) $datadogSpanEvent->timestamp, Attributes::create(array_merge($exceptionAttributes, \is_array($datadogSpanEvent->attributes) ? $datadogSpanEvent->attributes : iterator_to_array($datadogSpanEvent->attributes))));
                // Save the event
                ObjectKVStore::put($datadogSpanEvent, "event", $event);
            }
            $otel[] = $event;
        }
        // Update the events
        $this->events = $otel;
        $this->totalRecordedEvents = count($otel);
    }
    private function createAndSaveSpanLink(SpanContextInterface $context, iterable $attributes = [], ?LinkInterface $link = null)
    {
        $spanLink = new SpanLink();
        $spanLink->traceId = $context->getTraceId();
        $spanLink->spanId = $context->getSpanId();
        $spanLink->traceState = (string) $context->getTraceState();
        // __toString()
        $spanLink->attributes = $attributes;
        $spanLink->droppedAttributesCount = 0;
        // Attributes limit aren't supported/meaningful in DD
        // Save the link
        if (is_null($link)) {
            $link = new Link($context, Attributes::create($attributes));
        }
        ObjectKVStore::put($spanLink, "link", $link);
        return $spanLink;
    }
}
}

namespace OpenTelemetry\SDK\Trace {
use DDTrace\OpenTelemetry\API\Trace as DDTraceAPI;
use DDTrace\Tag;
use OpenTelemetry\API\Trace as API;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextKeys;
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
    public function __construct(string $spanName, InstrumentationScopeInterface $instrumentationScope, TracerSharedState $tracerSharedState)
    {
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
        $this->links[] = new Link($context, $this->tracerSharedState->getSpanLimits()->getLinkAttributesFactory()->builder($attributes)->build());
        return $this;
    }
    public function addEvent(string $name, iterable $attributes = [], ?int $timestamp = null): SpanBuilderInterface
    {
        $this->events[] = new Event($name, $timestamp ?? (int) (microtime(true) * 1000000000.0), $this->tracerSharedState->getSpanLimits()->getEventAttributesFactory()->builder($attributes)->build());
        return $this;
    }
    public function recordException(Throwable $exception, iterable $attributes = []): SpanBuilderInterface
    {
        // Standardized exception attributes
        $exceptionAttributes = ['exception.message' => $attributes['exception.message'] ?? $exception->getMessage(), 'exception.type' => $attributes['exception.type'] ?? get_class($exception), 'exception.stacktrace' => $attributes['exception.stacktrace'] ?? \DDTrace\get_sanitized_exception_trace($exception)];
        // Update span metadata based on exception stack
        $this->setAttribute(Tag::ERROR_STACK, $exceptionAttributes['exception.stacktrace']);
        // Merge additional attributes
        $allAttributes = array_merge($exceptionAttributes, \is_array($attributes) ? $attributes : iterator_to_array($attributes));
        // Record the exception event
        $this->addEvent('exception', $allAttributes);
        return $this;
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
        $samplingResult = $this->tracerSharedState->getSampler()->shouldSample($parentContext, $traceId, $this->spanName, $this->spanKind, Attributes::create($this->attributes), $this->links, $this->events);
        $span = $span ?? \DDTrace\start_trace_span($this->startEpochNanos);
        $samplingDecision = $samplingResult->getDecision();
        $sampled = SamplingResult::RECORD_AND_SAMPLE === $samplingDecision;
        $samplingResultTraceState = $samplingResult->getTraceState();
        if ($parentSpanContext->isValid()) {
            // Traceparent: {2:version}-{32:hex trace id}-{16:hex parent id}-{2:trace_flags}, version always being '00'
            // Since parentSpanContext is valid, the trace identifiers are guaranteed to be in hexadecimal format
            $parentId = $parentSpanContext->getSpanId();
            $traceFlags = $sampled ? '01' : '00';
            $traceParent = "00-{$traceId}-{$parentId}-{$traceFlags}";
            \DDTrace\consume_distributed_tracing_headers(['traceparent' => $traceParent, 'tracestate' => (string) $samplingResultTraceState]);
        } elseif ($samplingResultTraceState) {
            $samplingResultTraceState = $samplingResultTraceState->without('dd');
            \DDTrace\root_span()->tracestate = (string) $samplingResultTraceState;
        }
        $hexSpanId = $span->hexId();
        $spanContext = DDTraceAPI\SpanContext::createFromLocalSpan($span, $sampled, $traceId, $hexSpanId);
        if (!in_array($samplingDecision, [SamplingResult::RECORD_AND_SAMPLE, SamplingResult::RECORD_ONLY], true)) {
            return Span::wrap($spanContext);
        }
        $span->resource = $this->spanName;
        // OTel.name => DD.resource
        $attributes = $samplingResult->getAttributes();
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }
        $parentSpanContextBaggage = $parentContext->get(ContextKeys::baggage());
        if ($parentSpanContextBaggage) {
            foreach ($parentSpanContextBaggage->getAll() as $baggageKey => $baggageEntry) {
                $span->baggage[$baggageKey] = $baggageEntry->getValue();
            }
        }
        return Span::startSpan($span, $spanContext, $this->instrumentationScope, $this->spanKind, $parentSpan, $parentContext, $this->tracerSharedState->getSpanProcessor(), $parentSpanContext->isValid() ? ResourceInfoFactory::emptyResource() : $this->tracerSharedState->getResource(), $this->attributes, $this->links, $this->totalNumberOfLinksAdded, $this->events);
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
}

namespace {
// This file does not actually replace the CachedInstrumentation, but it's guaranteed to be autoloaded before the actual CachedInstrumentation.
// We just hook the CachedInstrumentation to track it.
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Common\InstrumentationScope\Configurator;
\DDTrace\install_hook('OpenTelemetry\API\Instrumentation\CachedInstrumentation::__construct', null, function () {
    dd_trace_internal_fn("mark_integration_loaded", $this->name, $this->version);
});
\DDTrace\install_hook('OpenTelemetry\API\Instrumentation\CachedInstrumentation::tracer', null, function (\DDTrace\HookData $hook) {
    $tracer = $hook->returned;
    $name = $this->name;
    if (strpos($name, "io.opentelemetry.contrib.php.") === 0) {
        $name = substr($name, strlen("io.opentelemetry.contrib.php."));
    }
    $name = "otel.{$name}";
    $hook->overrideReturnValue(new class($tracer, $name) implements \OpenTelemetry\API\Trace\TracerInterface
    {
        public $tracer;
        public $name;
        public function __construct($tracer, $name)
        {
            $this->tracer = $tracer;
            $this->name = $name;
        }
        public function spanBuilder(string $spanName): \OpenTelemetry\API\Trace\SpanBuilderInterface
        {
            $spanBuilder = $this->tracer->spanBuilder($spanName);
            $spanBuilder->setAttribute("component", $this->name);
            return $spanBuilder;
        }
        public function isEnabled(): bool
        {
            return $this->tracer->isEnabled();
        }
        public function getInstrumentationScope(): InstrumentationScopeInterface
        {
            if (!method_exists($this->tracer, 'getInstrumentationScope')) {
                throw new \Error("There is no getInstrumentationScope method available for " . get_class($this->tracer));
            }
            return $this->tracer->getInstrumentationScope();
        }
        public function updateConfig(Configurator $configurator): void
        {
            $this->tracer->updateConfig($configurator);
        }
    });
});
}

// This file does not actually replace the CompositeResolver, but it's guaranteed to be autoloaded before the actual CompositeResolver.
// We just hook the CompositeResolver to track it.
namespace DDTrace\OpenTelemetry {
use OpenTelemetry\Contrib\Otlp\OtlpUtil;
use OpenTelemetry\API\Signals;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Resolver\ResolverInterface;
class DatadogResolver implements ResolverInterface
{
    private const DEFAULT_PROTOCOL = 'http/protobuf';
    private const GRPC_PORT = '4317';
    private const HTTP_PORT = '4318';
    private const DEFAULT_HOST = 'localhost';
    private const DEFAULT_SCHEME = 'http';
    public function retrieveValue(string $name)
    {
        if (!$this->isMetricsEnabled($name)) {
            return null;
        }
        if ($name === 'OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE') {
            return 'delta';
        }
        if ($name === 'OTEL_EXPORTER_OTLP_ENDPOINT' || $name === 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT') {
            return $this->resolveEndpoint($name);
        }
        return null;
    }
    public function hasVariable(string $variableName): bool
    {
        if ($variableName === 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT' || $variableName === 'OTEL_EXPORTER_OTLP_ENDPOINT' || $variableName === 'OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE') {
            return \dd_trace_env_config('DD_METRICS_OTEL_ENABLED');
        }
        return false;
    }
    private function isMetricsEnabled(string $name): bool
    {
        $metricsOnlySettings = ['OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE', 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT'];
        if (in_array($name, $metricsOnlySettings, true)) {
            return \dd_trace_env_config('DD_METRICS_OTEL_ENABLED');
        }
        return true;
    }
    private function resolveEndpoint(string $name): string
    {
        $isMetricsEndpoint = $name === 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT';
        $protocol = $this->resolveProtocol($isMetricsEndpoint);
        // Check for user-configured general OTLP endpoint (only when requesting metrics endpoint)
        if ($isMetricsEndpoint && Configuration::has('OTEL_EXPORTER_OTLP_ENDPOINT')) {
            return $this->buildMetricsEndpointFromGeneral($protocol);
        }
        return $this->buildEndpointFromAgent($protocol, $isMetricsEndpoint);
    }
    private function resolveProtocol(bool $metricsSpecific): ?string
    {
        if ($metricsSpecific && Configuration::has('OTEL_EXPORTER_OTLP_METRICS_PROTOCOL')) {
            return Configuration::getEnum('OTEL_EXPORTER_OTLP_METRICS_PROTOCOL');
        }
        // Call getEnum without has() check to match original behavior -
        // allows SDK defaults to be applied if they exist
        $protocol = Configuration::getEnum('OTEL_EXPORTER_OTLP_PROTOCOL');
        return $protocol ?? self::DEFAULT_PROTOCOL;
    }
    private function buildMetricsEndpointFromGeneral(string $protocol): string
    {
        $generalEndpoint = rtrim(Configuration::getString('OTEL_EXPORTER_OTLP_ENDPOINT'), '/');
        if ($this->isGrpc($protocol)) {
            return $generalEndpoint . OtlpUtil::method(Signals::METRICS);
        }
        return $generalEndpoint . '/v1/metrics';
    }
    private function buildEndpointFromAgent(string $protocol, bool $isMetricsEndpoint): string
    {
        $agentInfo = $this->resolveAgentInfo();
        // Unix sockets: pass through the full URL
        if ($agentInfo['scheme'] === 'unix') {
            return $agentInfo['url'];
        }
        $port = $this->isGrpc($protocol) ? self::GRPC_PORT : self::HTTP_PORT;
        $endpoint = $agentInfo['scheme'] . '://' . $agentInfo['host'] . ':' . $port;
        if ($isMetricsEndpoint) {
            return $this->appendMetricsPath($endpoint, $protocol);
        }
        return $endpoint;
    }
    /**
     * Resolves agent connection info from DD_TRACE_AGENT_URL or DD_AGENT_HOST.
     *
     * @return array{scheme: string, host: string, url?: string}
     */
    private function resolveAgentInfo(): array
    {
        $scheme = self::DEFAULT_SCHEME;
        $host = null;
        $agentUrl = \dd_trace_env_config('DD_TRACE_AGENT_URL');
        if ($agentUrl !== '') {
            $component = \parse_url($agentUrl);
            if ($component !== false) {
                $scheme = $component['scheme'] ?? self::DEFAULT_SCHEME;
                // Handle unix scheme - return full URL for pass-through
                if ($scheme === 'unix') {
                    return ['scheme' => 'unix', 'host' => '', 'url' => $agentUrl];
                }
                $host = $component['host'] ?? null;
            }
        }
        // Fall back to DD_AGENT_HOST if no host was found
        if ($host === null) {
            $ddAgentHost = \dd_trace_env_config('DD_AGENT_HOST');
            if ($ddAgentHost !== '') {
                $host = $ddAgentHost;
            }
        }
        // Default to localhost if host is still empty
        if ($host === null || $host === '') {
            $host = self::DEFAULT_HOST;
        }
        return ['scheme' => $scheme, 'host' => $host];
    }
    private function appendMetricsPath(string $endpoint, string $protocol): string
    {
        if ($this->isGrpc($protocol)) {
            return $endpoint . OtlpUtil::method(Signals::METRICS);
        }
        return $endpoint . '/v1/metrics';
    }
    private function isGrpc(string $protocol): bool
    {
        return strtolower($protocol) === 'grpc';
    }
}
\DDTrace\install_hook('OpenTelemetry\SDK\Common\Configuration\Resolver\CompositeResolver::__construct', null, function (\DDTrace\HookData $hook) {
    $this->addResolver(new DatadogResolver());
});
}

namespace {
// This file hooks the OpenTelemetry Configuration class to track OTel metrics configuration access for telemetry
// Whitelist of OTel configurations we want to track for telemetry
const OTEL_CONFIG_WHITELIST = [
    // OpenTelemetry Metrics SDK Configurations
    'OTEL_RESOURCE_ATTRIBUTES',
    'OTEL_METRICS_EXPORTER',
    'OTEL_METRIC_EXPORT_INTERVAL',
    'OTEL_METRIC_EXPORT_TIMEOUT',
    // OTLP Exporter Configurations
    'OTEL_EXPORTER_OTLP_METRICS_PROTOCOL',
    'OTEL_EXPORTER_OTLP_PROTOCOL',
    'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT',
    'OTEL_EXPORTER_OTLP_ENDPOINT',
    'OTEL_EXPORTER_OTLP_METRICS_HEADERS',
    'OTEL_EXPORTER_OTLP_HEADERS',
    'OTEL_EXPORTER_OTLP_METRICS_TIMEOUT',
    'OTEL_EXPORTER_OTLP_TIMEOUT',
    'OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE',
];
// Helper function to track config access
function track_otel_config_if_whitelisted(string $name, $value): void
{
    if (in_array($name, OTEL_CONFIG_WHITELIST, true)) {
        // Convert value to string for telemetry
        if (is_bool($value)) {
            $value_str = $value ? 'true' : 'false';
        } elseif (is_null($value)) {
            $value_str = '';
        } elseif (is_array($value)) {
            $value_str = json_encode($value);
        } elseif (is_object($value)) {
            $value_str = get_class($value);
        } else {
            $value_str = (string) $value;
        }
        \dd_trace_internal_fn('track_otel_config', $name, $value_str);
    }
}
// Helper function to install config tracking hooks
function install_config_tracking_hook(string $methodName): void
{
    \DDTrace\install_hook("OpenTelemetry\\SDK\\Common\\Configuration\\Configuration::{$methodName}", function (\DDTrace\HookData $hook) {
        $name = $hook->args[0] ?? null;
        if ($name && is_string($name)) {
            $hook->data = $name;
        }
    }, function (\DDTrace\HookData $hook) {
        if (isset($hook->data) && $hook->returned !== null) {
            track_otel_config_if_whitelisted($hook->data, $hook->returned);
        }
    });
}
// Install hooks for all Configuration getter methods
foreach (['getString', 'getInt', 'getBoolean', 'getMixed', 'getMap', 'getList', 'getEnum'] as $method) {
    install_config_tracking_hook($method);
}
}

namespace DDTrace\OpenTelemetry\Detectors {
use OpenTelemetry\SDK\Common\Attribute\AttributesFactory;
use OpenTelemetry\SDK\Resource\ResourceInfo;
class DetectorHelper
{
    /**
     * Merges additional attributes into the resource returned by a detector hook.
     *
     * @param \DDTrace\HookData $hook The hook data containing the returned resource
     * @param array<string, mixed> $attributes Attributes to merge into the resource
     */
    public static function mergeAttributes(\DDTrace\HookData $hook, array $attributes): void
    {
        if (empty($attributes)) {
            return;
        }
        $builder = (new AttributesFactory())->builder($attributes);
        $newResource = ResourceInfo::create($builder->build());
        $mergedResource = $hook->returned->merge($newResource);
        $hook->overrideReturnValue($mergedResource);
    }
}
}

namespace {
use DDTrace\OpenTelemetry\Detectors\DetectorHelper;
\DDTrace\install_hook('OpenTelemetry\SDK\Resource\Detectors\Environment::getResource', null, function (\DDTrace\HookData $hook) {
    $attributes = [];
    $ddEnv = \dd_trace_env_config('DD_ENV');
    if ($ddEnv !== '') {
        $attributes['deployment.environment.name'] = $ddEnv;
    }
    $ddVersion = \dd_trace_env_config('DD_VERSION');
    if ($ddVersion !== '') {
        $attributes['service.version'] = $ddVersion;
    }
    foreach (\dd_trace_env_config('DD_TAGS') as $key => $value) {
        $attributes[$key] = $value;
    }
    DetectorHelper::mergeAttributes($hook, $attributes);
});
}

namespace {
use DDTrace\OpenTelemetry\Detectors\DetectorHelper;
\DDTrace\install_hook('OpenTelemetry\SDK\Resource\Detectors\Host::getResource', null, function (\DDTrace\HookData $hook) {
    $attributes = [];
    if (\dd_trace_env_config('DD_TRACE_REPORT_HOSTNAME')) {
        $ddHostname = \dd_trace_env_config('DD_HOSTNAME');
        // Only override if DD_HOSTNAME is explicitly set to avoid
        // clobbering the hostname detected by OTel's Host detector
        if ($ddHostname !== '') {
            $attributes['host.name'] = $ddHostname;
        }
    }
    DetectorHelper::mergeAttributes($hook, $attributes);
});
}

namespace {
use DDTrace\OpenTelemetry\Detectors\DetectorHelper;
\DDTrace\install_hook('OpenTelemetry\SDK\Resource\Detectors\Service::getResource', null, function (\DDTrace\HookData $hook) {
    $attributes = [];
    $rootSpan = \DDTrace\root_span();
    if ($rootSpan) {
        $attributes['service.name'] = $rootSpan->service;
    } else {
        $appName = \ddtrace_config_app_name();
        if ($appName === '') {
            return;
        }
        $attributes['service.name'] = $appName;
    }
    DetectorHelper::mergeAttributes($hook, $attributes);
});
}

