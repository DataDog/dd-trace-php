<?php

declare(strict_types=1);

namespace OpenTelemetry\Context;

use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Util\ObjectKVStore;
use OpenTelemetry\API\Trace as API;
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

    // Optimization for spans to avoid copying the context array.
    private static ContextKeyInterface $spanContextKey;
    private ?object $span = null;
    /** @var array<int, mixed> */
    private array $context = [];
    /** @var array<int, ContextKeyInterface> */
    private array $contextKeys = [];

    private function __construct()
    {
        self::$spanContextKey = ContextKeys::span();
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
        if (class_exists('\OpenTelemetry\Context\FiberBoundContextStorageExecutionAwareBC')) {
            return self::$storage ??= new FiberBoundContextStorageExecutionAwareBC();
        } else {
            return self::$storage ??= new ContextStorage();
        }
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

        return $context
            ?? ($contextStorage ?? self::storage())->current()
            ?: self::getRoot();
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
        if ($currentSpan === null) { // Terminal condition - root span
            return;
        }

        if ($currentSpan->getDuration() === 0) {
            // The span is still active, so its parents are still active
            return;
        }

        // The dd span is ended, so end the OTel span
        /** @var SDK\Span $OTelCurrentSpan */
        $OTelCurrentSpan = ObjectKVStore::get($currentSpan, 'otel_span'); // Note: SDK\Span::startSpan() associates the DDTrace span with the OTel span when it is created
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
        if ($currentSpan === null) { // Terminal condition - root span
            return self::storage()->current();
        }

        /** @var SDK\Span $OTelCurrentSpan */
        $OTelCurrentSpan = ObjectKVStore::get($currentSpan, 'otel_span'); // Note: SDK\Span::startSpan() associates the DDTrace span with the OTel span when it is created
        if ($OTelCurrentSpan !== null) { // If the current span has been activated, nothing to do, trigger backtalk
            // Return the context associated with the current span
            if (ObjectKVStore::get($currentSpan, 'ddtrace_scope_activated')) {
                return self::storage()->current()->with(self::$spanContextKey, $OTelCurrentSpan);
            } else {
                return self::storage()->current();
            }
        }

        $parentContext = self::activateParent($currentSpan->parent); // Activates the ancestors first

        // Create a new span from the current span
        $currentSpanId = $currentSpan->hexId();
        $currentTraceId = \DDTrace\root_span()->traceId;
        $traceContext = generate_distributed_tracing_headers(['tracecontext']);
        $traceFlags = isset($traceContext['traceparent'])
            ? (substr($traceContext['traceparent'], -2) === '01' ? API\TraceFlags::SAMPLED : API\TraceFlags::DEFAULT)
            : null;
        $traceState = new API\TraceState($traceContext['tracestate'] ?? null);

        // Check for span links
        $links = [];
        foreach ($currentSpan->links as $spanLink) {
            $linkSpanContext = API\SpanContext::create(
                $spanLink->traceId,
                $spanLink->spanId,
                API\TraceFlags::DEFAULT,
                new API\TraceState($spanLink->traceState ?? null),
            );
            $links[] = new SDK\Link($linkSpanContext, Attributes::create($spanLink->attributes ?? []));
        }

        // Check for span events
        $events = [];
        foreach ($currentSpan->events as $spanEvent) {
            $events[] = new SDK\Event($spanEvent->name, (int)$spanEvent->timestamp, Attributes::create((array)$spanEvent->attributes ?? []));
        }

        $OTelCurrentSpan = SDK\Span::startSpan(
            $currentSpan,
            API\SpanContext::create($currentTraceId, $currentSpanId, $traceFlags, $traceState), // $context
            self::getDDInstrumentationScope(), // $instrumentationScope
            isset($currentSpan->meta[Tag::SPAN_KIND]) ? self::convertDDSpanKindToOtel($currentSpan->meta[Tag::SPAN_KIND]) : API\SpanKind::KIND_INTERNAL, // $kind
            API\Span::fromContext($parentContext), // $parentSpan (TODO: Handle null parent span) ?
            $parentContext, // $parentContext
            NoopSpanProcessor::getInstance(), // $spanProcessor
            ResourceInfoFactory::emptyResource(), // $resource
            [], // $attributesBuilder
            $links, // $links
            count($links), // $totalRecordedLinks
            $events, //$events
            false // The span was created using the DD Api
        );
        ObjectKVStore::put($currentSpan, 'otel_span', $OTelCurrentSpan);
        $currentContext = $parentContext->with(self::$spanContextKey, $OTelCurrentSpan); // Sets the current span in the context
        ObjectKVStore::put($currentSpan, 'ddtrace_scope_activated', true);
        self::storage()->attach($currentContext); // TODO: Handle Detach

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
            $self->span = $value; // @phan-suppress-current-line PhanTypeMismatchPropertyReal

            return $self;
        }

        $id = spl_object_id($key);
        if ($value !== null) {
            $self->context[$id] = $value;
            $self->contextKeys[$id] ??= $key;
        } else {
            unset(
                $self->context[$id],
                $self->contextKeys[$id],
            );
        }

        return $self;
    }

    public function get(ContextKeyInterface $key)
    {
        if ($key === self::$spanContextKey) {
            /** @psalm-suppress InvalidReturnStatement */
            return $this->span;
        }

        return $this->context[spl_object_id($key)] ?? null;
    }
}
