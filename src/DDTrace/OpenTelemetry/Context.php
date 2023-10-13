<?php

declare(strict_types=1);

namespace OpenTelemetry\Context;

use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Util\ObjectKVStore;
use OpenTelemetry\API\Trace as API;
use OpenTelemetry\SDK\Common\Attribute\AttributesFactory;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScope;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace as SDK;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\NoopSpanProcessor;
use function assert;
use function DDTrace\active_span;
use function DDTrace\generate_distributed_tracing_headers;
use function DDTrace\trace_id;
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
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        return self::$storage ??= new ContextStorage();
    }

    /**
     * @param ContextInterface|false|null $context
     *
     * @internal OpenTelemetry
     */
    public static function resolve($context, ?ContextStorageInterface $contextStorage = null): ContextInterface
    {
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
        if (active_span()) {
            print("[>Context] Active span id: " . str_pad(strtolower(self::largeBaseConvert(active_span()?->id, 10, 16)), 16, '0', STR_PAD_LEFT) . PHP_EOL);
        }
        $spanFromContext = API\Span::fromContext(self::storage()->current());
        if ($spanFromContext instanceof SDK\Span) {
            $ddSpanFromContext = $spanFromContext->getDDSpan();
            self::desactivateEndedParents($ddSpanFromContext);
        }

        $currentSpanId = self::storage()->current()->get(self::$spanContextKey)?->getContext()->getSpanId();
        print("[Context] Current Span Id: $currentSpanId\n");
        $currentSpanDDSpan = $currentSpanId = self::storage()->current()->get(self::$spanContextKey)?->getDDSpan()->getDuration();
        print("[Context] Has a dd span? $currentSpanDDSpan\n");
        print("[Context] Active spans's duration: " . active_span()?->getDuration() . PHP_EOL);
        if (active_span()) {
            print("[<Context] Active span id: " . str_pad(strtolower(self::largeBaseConvert(active_span()?->id, 10, 16)), 16, '0', STR_PAD_LEFT) . PHP_EOL);
        }
        return self::activateParent(active_span());
    }

    private static function desactivateEndedParents(?SpanData $currentSpan)
    {
        $_spanId = $currentSpan
            ? str_pad(strtolower(self::largeBaseConvert($currentSpan->id, 10, 16)), 16, '0', STR_PAD_LEFT)
            : "null";
        print("Desactivating ended parents of span $_spanId\n");

        if ($currentSpan === null) { // Terminal condition - root span
            print("No current span\n");
            return;
        }

        if ($currentSpan->getDuration() === 0) {
            // The span is still active, so its parents are still active
            print("Span $_spanId is still active\n");
            return;
        }

        // The dd span is ended, so end the OTel span
        /** @var SDK\Span $OTelCurrentSpan */
        $OTelCurrentSpan = ObjectKVStore::get($currentSpan, 'otel_span'); // Note: SDK\Span::startSpan() associates the DDTrace span with the OTel span when it is created
        if ($OTelCurrentSpan !== null) {
            print("Ending span {$OTelCurrentSpan->getContext()->getSpanId()}\n");
            $OTelCurrentSpan->endOTelSpan();
        }

        // End the parent spans
        self::desactivateEndedParents($currentSpan->parent);
    }

    private static function activateParent(?SpanData $currentSpan): ContextInterface
    {
        $_spanId = $currentSpan
            ? str_pad(strtolower(self::largeBaseConvert($currentSpan->id, 10, 16)), 16, '0', STR_PAD_LEFT)
            : "null";
        print("Activating parent of span $_spanId\n");
        if ($currentSpan === null) { // Terminal condition - root span
            print("No current span\n");
            return self::storage()->current();
            //return self::getRoot();
        }

        /** @var SDK\Span $OTelCurrentSpan */
        $OTelCurrentSpan = ObjectKVStore::get($currentSpan, 'otel_span'); // Note: SDK\Span::startSpan() associates the DDTrace span with the OTel span when it is created
        if ($OTelCurrentSpan !== null) { // If the current span has been activated, nothing to do, trigger backtalk
            print("Current span {$OTelCurrentSpan->getContext()->getSpanId()} otel exists\n");
            // Return the context associated with the current span
            if (ObjectKVStore::get($currentSpan, 'ddtrace_scope_activated')) {
                print("Current span {$OTelCurrentSpan->getContext()->getSpanId()} already activated\n");
                return self::storage()->current()->with(self::$spanContextKey, $OTelCurrentSpan);
            } else {
                print("Current span {$OTelCurrentSpan->getContext()->getSpanId()} not activated\n");
                return self::storage()->current();
            }
            return self::storage()->current()->with(self::$spanContextKey, $OTelCurrentSpan);
            //return self::storage()->current();
            //$currentContext = self::storage()->current()->with(self::$spanContextKey, $OTelCurrentSpan);
            //self::storage()->attach($currentContext); // TODO: Handle Detach
            //return $currentContext;
        }

        $parentContext = self::activateParent($currentSpan->parent); // Activates the ancestors first

        // Create a new span from the current span
        $currentSpanId = str_pad(self::largeBaseConvert($currentSpan->id, 10, 16), 16, '0', STR_PAD_LEFT);
        $currentTraceId = str_pad(self::largeBaseConvert(trace_id(), 10, 16), 32, '0', STR_PAD_LEFT);
        $traceContext = generate_distributed_tracing_headers(['tracecontext']);
        $traceFlags = isset($traceContext['traceparent'])
            ? (substr($traceContext['traceparent'], -2) === '01' ? API\TraceFlags::SAMPLED : API\TraceFlags::DEFAULT)
            : null;
        $traceState = new API\TraceState($traceContext['tracestate'] ?? null);

        $OTelCurrentSpan = SDK\Span::startSpan(
            $currentSpan,
            API\SpanContext::create($currentTraceId, $currentSpanId, $traceFlags, $traceState), // $context
            self::getDDInstrumentationScope(), // $instrumentationScope
            $currentSpan->meta[Tag::SPAN_KIND] ?? API\SpanKind::KIND_INTERNAL, // $kind
            API\Span::fromContext($parentContext), // $parentSpan (TODO: Handle null parent span) ?
            $parentContext, // $parentContext
            NoopSpanProcessor::getInstance(), // $spanProcessor
            ResourceInfoFactory::defaultResource(), // $resource
            (new AttributesFactory())->builder(), // $attributesBuilder
            [], // TODO: Handle Span Links
            0, // TODO: Handle Span Links
            $currentSpan->getStartTime()
        );
        ObjectKVStore::put($currentSpan, 'otel_span', $OTelCurrentSpan);
        print("Created span {$OTelCurrentSpan->getContext()->getSpanId()}\n");
        $currentContext = $parentContext->with(self::$spanContextKey, $OTelCurrentSpan); // Sets the current span in the context
        ObjectKVStore::put($currentSpan, 'ddtrace_scope_activated', true);
        self::storage()->attach($currentContext); // TODO: Handle Detach

        return $currentContext;
    }

    public function activate(): ScopeInterface
    {
        // TODO: Context::getCurrent should already have been called, so the current span should be set, but test it anyway
        if ($this->span instanceof SDK\Span) {
            ObjectKVStore::put($this->span->getDDSpan(), 'ddtrace_scope_activated', true);
        }
        $scope = self::storage()->attach($this);
        /** @psalm-suppress RedundantCondition */
        //assert((bool) $scope = new DebugScope($scope));

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
