<?php

namespace OpenTelemetry\Context;

/**
 * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/context/README.md#context
 */
final class Context implements \OpenTelemetry\Context\ContextInterface
{
    public static function createKey(string $key) : \OpenTelemetry\Context\ContextKeyInterface
    {
    }
    /**
     * @param ContextStorageInterface&ExecutionContextAwareInterface $storage
     */
    public static function setStorage(\OpenTelemetry\Context\ContextStorageInterface $storage) : void
    {
    }
    /**
     * @return ContextStorageInterface&ExecutionContextAwareInterface
     */
    public static function storage() : \OpenTelemetry\Context\ContextStorageInterface
    {
    }
    /**
     * @param ContextInterface|false|null $context
     *
     * @internal OpenTelemetry
     */
    public static function resolve($context, ?\OpenTelemetry\Context\ContextStorageInterface $contextStorage = null) : \OpenTelemetry\Context\ContextInterface
    {
    }
    /**
     * @internal
     */
    public static function getRoot() : \OpenTelemetry\Context\ContextInterface
    {
    }
    public static function getCurrent() : \OpenTelemetry\Context\ContextInterface
    {
    }
    public function activate() : \OpenTelemetry\Context\ScopeInterface
    {
    }
    public function withContextValue(\OpenTelemetry\Context\ImplicitContextKeyedInterface $value) : \OpenTelemetry\Context\ContextInterface
    {
    }
    public function with(\OpenTelemetry\Context\ContextKeyInterface $key, $value) : self
    {
    }
    public function get(\OpenTelemetry\Context\ContextKeyInterface $key)
    {
    }
}
namespace DDTrace\OpenTelemetry;

// Operation Name Conventions
class Convention
{
    public static function defaultOperationName(\DDTrace\SpanData $span) : string
    {
    }
}
namespace OpenTelemetry\SDK\Trace;

final class Span extends \OpenTelemetry\API\Trace\Span implements \OpenTelemetry\SDK\Trace\ReadWriteSpanInterface
{
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
    public static function startSpan(\DDTrace\SpanData $span, \OpenTelemetry\API\Trace\SpanContextInterface $context, \OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface $instrumentationScope, int $kind, \OpenTelemetry\API\Trace\SpanInterface $parentSpan, \OpenTelemetry\Context\ContextInterface $parentContext, \OpenTelemetry\SDK\Trace\SpanProcessorInterface $spanProcessor, \OpenTelemetry\SDK\Resource\ResourceInfo $resource, array $attributes, array $links, int $totalRecordedLinks, array $events, bool $isRemapped = true) : self
    {
    }
    public function getName() : string
    {
    }
    /**
     * @inheritDoc
     */
    public function getContext() : \OpenTelemetry\API\Trace\SpanContextInterface
    {
    }
    public function getParentContext() : \OpenTelemetry\API\Trace\SpanContextInterface
    {
    }
    public function getInstrumentationScope() : \OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface
    {
    }
    public function hasEnded() : bool
    {
    }
    /**
     * @inheritDoc
     */
    public function toSpanData() : \OpenTelemetry\SDK\Trace\SpanDataInterface
    {
    }
    /**
     * @inheritDoc
     */
    public function getDuration() : int
    {
    }
    /**
     * @inheritDoc
     */
    public function getKind() : int
    {
    }
    /**
     * @inheritDoc
     */
    public function getAttribute(string $key)
    {
    }
    public function getStartEpochNanos() : int
    {
    }
    public function getTotalRecordedLinks() : int
    {
    }
    public function getTotalRecordedEvents() : int
    {
    }
    /**
     * @inheritDoc
     */
    public function isRecording() : bool
    {
    }
    /**
     * @inheritDoc
     */
    public function setAttribute(string $key, $value) : \OpenTelemetry\API\Trace\SpanInterface
    {
    }
    /**
     * @inheritDoc
     */
    public function setAttributes(iterable $attributes) : \OpenTelemetry\API\Trace\SpanInterface
    {
    }
    /**
     * @inheritDoc
     */
    public function addLink(\OpenTelemetry\API\Trace\SpanContextInterface $context, iterable $attributes = []) : \OpenTelemetry\API\Trace\SpanInterface
    {
    }
    /**
     * @inheritDoc
     */
    public function addEvent(string $name, iterable $attributes = [], int $timestamp = null) : \OpenTelemetry\API\Trace\SpanInterface
    {
    }
    /**
     * @inheritDoc
     */
    public function recordException(\Throwable $exception, iterable $attributes = []) : \OpenTelemetry\API\Trace\SpanInterface
    {
    }
    /**
     * @inheritDoc
     */
    public function updateName(string $name) : \OpenTelemetry\API\Trace\SpanInterface
    {
    }
    /**
     * @inheritDoc
     */
    public function setStatus(string $code, string $description = null) : \OpenTelemetry\API\Trace\SpanInterface
    {
    }
    /**
     * @inheritDoc
     */
    public function end(int $endEpochNanos = null) : void
    {
    }
    public function endOTelSpan(int $endEpochNanos = null) : void
    {
    }
    public function getResource() : \OpenTelemetry\SDK\Resource\ResourceInfo
    {
    }
    /**
     * @internal
     * @return SpanData
     */
    public function getDDSpan() : \DDTrace\SpanData
    {
    }
}
final class SpanBuilder implements \OpenTelemetry\API\Trace\SpanBuilderInterface
{
    /** @param non-empty-string $spanName */
    public function __construct(string $spanName, \OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeInterface $instrumentationScope, \OpenTelemetry\SDK\Trace\TracerSharedState $tracerSharedState)
    {
    }
    /**
     * @inheritDoc
     */
    public function setParent($context) : \OpenTelemetry\API\Trace\SpanBuilderInterface
    {
    }
    public function addLink(\OpenTelemetry\API\Trace\SpanContextInterface $context, iterable $attributes = []) : \OpenTelemetry\API\Trace\SpanBuilderInterface
    {
    }
    public function addEvent(string $name, iterable $attributes = [], int $timestamp = null) : \OpenTelemetry\API\Trace\SpanBuilderInterface
    {
    }
    public function recordException(\Throwable $exception, iterable $attributes = []) : \OpenTelemetry\API\Trace\SpanBuilderInterface
    {
    }
    /** @inheritDoc */
    public function setAttribute(string $key, $value) : \OpenTelemetry\API\Trace\SpanBuilderInterface
    {
    }
    /** @inheritDoc */
    public function setAttributes(iterable $attributes) : \OpenTelemetry\API\Trace\SpanBuilderInterface
    {
    }
    /**
     * @inheritDoc
     */
    public function setStartTimestamp(int $timestampNanos) : \OpenTelemetry\API\Trace\SpanBuilderInterface
    {
    }
    /**
     * @inheritDoc
     */
    public function setSpanKind(int $spanKind) : \OpenTelemetry\API\Trace\SpanBuilderInterface
    {
    }
    /**
     * @inheritDoc
     */
    public function startSpan() : \OpenTelemetry\API\Trace\SpanInterface
    {
    }
}
namespace DDTrace\OpenTelemetry\API\Trace;

final class SpanContext implements \OpenTelemetry\API\Trace\SpanContextInterface
{
    /**
     * @inheritDoc
     */
    public function getTraceId() : string
    {
    }
    public function getTraceIdBinary() : string
    {
    }
    /**
     * @inheritDoc
     */
    public function getSpanId() : string
    {
    }
    public function getSpanIdBinary() : string
    {
    }
    public function getTraceState() : ?\OpenTelemetry\API\Trace\TraceStateInterface
    {
    }
    public function isSampled() : bool
    {
    }
    public function isValid() : bool
    {
    }
    public function isRemote() : bool
    {
    }
    public function getTraceFlags() : int
    {
    }
    /** @inheritDoc */
    public static function createFromRemoteParent(string $traceId, string $spanId, int $traceFlags = \OpenTelemetry\API\Trace\TraceFlags::DEFAULT, ?\OpenTelemetry\API\Trace\TraceStateInterface $traceState = null) : \OpenTelemetry\API\Trace\SpanContextInterface
    {
    }
    /** @inheritDoc */
    public static function create(string $traceId, string $spanId, int $traceFlags = \OpenTelemetry\API\Trace\TraceFlags::DEFAULT, ?\OpenTelemetry\API\Trace\TraceStateInterface $traceState = null) : \OpenTelemetry\API\Trace\SpanContextInterface
    {
    }
    /** @inheritDoc */
    public static function getInvalid() : \OpenTelemetry\API\Trace\SpanContextInterface
    {
    }
    public static function createFromLocalSpan(\DDTrace\SpanData $span, bool $sampled, ?string $traceId = null, ?string $spanId = null)
    {
    }
}
namespace DDTrace\Processing;

/**
 * A span processor in charge of adding the trace analytics client config metric when appropriate.
 *
 * NOTE: this may be transformer into a filter for consistency with other tracers, but for now we did not implement
 * any filtering functionality so giving it such name as of now might be misleading.
 */
final class TraceAnalyticsProcessor
{
    /**
     * @param array $metrics
     * @param bool|float $value
     */
    public static function normalizeAnalyticsValue(&$metrics, $value)
    {
    }
}
namespace DDTrace;

/**
 * Propagator implementations should be able to inject and extract
 * SpanContexts into an implementation specific carrier.
 */
interface Propagator
{
    const DEFAULT_BAGGAGE_HEADER_PREFIX = 'ot-baggage-';
    const DEFAULT_TRACE_ID_HEADER = 'x-datadog-trace-id';
    const DEFAULT_PARENT_ID_HEADER = 'x-datadog-parent-id';
    const DEFAULT_SAMPLING_PRIORITY_HEADER = 'x-datadog-sampling-priority';
    const DEFAULT_ORIGIN_HEADER = 'x-datadog-origin';
    /**
     * Inject takes the SpanContext and injects it into the carrier using
     * an implementation specific method.
     *
     * @param SpanContextInterface $spanContext
     * @param array|\ArrayAccess $carrier
     * @return void
     */
    public function inject(\DDTrace\Contracts\SpanContext $spanContext, &$carrier);
    /**
     * Extract returns the SpanContext from the given carrier using an
     * implementation specific method.
     *
     * @param array|\ArrayAccess $carrier
     * @return SpanContextInterface
     */
    public function extract($carrier);
}
namespace DDTrace\Log;

trait LoggingTrait
{
    /**
     * Emits a log message at debug level.
     *
     * @param string $message
     * @param array $context
     */
    protected static function logDebug($message, array $context = [])
    {
    }
    /**
     * Emits a log message at warning level.
     *
     * @param string $message
     * @param array $context
     */
    protected static function logWarning($message, array $context = [])
    {
    }
    /**
     * Emits a log message at error level.
     *
     * @param string $message
     * @param array $context
     */
    protected static function logError($message, array $context = [])
    {
    }
    /**
     * @return bool
     */
    protected static function isLogDebugActive()
    {
    }
}
namespace DDTrace\Propagators;

final class TextMap implements \DDTrace\Propagator
{
    use \DDTrace\Log\LoggingTrait;
    /**
     * @param Tracer $tracer
     */
    public function __construct(\DDTrace\Contracts\Tracer $tracer)
    {
    }
    /**
     * {@inheritdoc}
     */
    public function inject(\DDTrace\Contracts\SpanContext $spanContext, &$carrier)
    {
    }
    /**
     * {@inheritdoc}
     */
    public function extract($carrier)
    {
    }
}
namespace DDTrace\Contracts;

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/Scope.php
 */
/**
 * A {@link Scope} formalizes the activation and deactivation of a {@link Span}, usually from a CPU standpoint.
 *
 * Many times a {@link Span} will be extant (in that {@link Span#finish()} has not been called) despite being in a
 * non-runnable state from a CPU/scheduler standpoint. For instance, a {@link Span} representing the client side of an
 * RPC will be unfinished but blocked on IO while the RPC is still outstanding. A {@link Scope} defines when a given
 * {@link Span} <em>is</em> scheduled and on the path.
 */
interface Scope
{
    /**
     * Mark the end of the active period for the current thread and {@link Scope},
     * updating the {@link ScopeManager#active()} in the process.
     *
     * NOTE: Calling {@link #close} more than once on a single {@link Scope} instance leads to undefined
     * behavior.
     */
    public function close();
    /**
     * @return Span the {@link Span} that's been scoped by this {@link Scope}
     */
    public function getSpan();
}
namespace DDTrace;

final class Scope implements \DDTrace\Contracts\Scope
{
    public function __construct(\DDTrace\ScopeManager $scopeManager, \DDTrace\Contracts\Span $span, $finishSpanOnClose)
    {
    }
    /**
     * {@inheritdoc}
     */
    public function close()
    {
    }
    /**
     * {@inheritdoc}
     *
     * @return SpanInterface
     */
    public function getSpan()
    {
    }
}
namespace DDTrace\Contracts;

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/ScopeManager.php
 */
/**
 * Keeps track of the current active `Span`.
 */
interface ScopeManager
{
    const DEFAULT_FINISH_SPAN_ON_CLOSE = true;
    /**
     * Activates an `Span`, so that it is used as a parent when creating new spans.
     * The implementation must keep track of the active spans sequence, so
     * that previous spans can be resumed after a deactivation.
     *
     * @param Span $span the {@link Span} that should become the {@link #active()}
     * @param bool $finishSpanOnClose whether span should automatically be finished
     * when {@link Scope#close()} is called. Its default value is true.
     *
     * @return Scope instance to control the end of the active period for the {@link Span}. It is a
     * programming error to neglect to call {@link Scope#close()} on the returned instance.
     */
    public function activate(\DDTrace\Contracts\Span $span, $finishSpanOnClose = self::DEFAULT_FINISH_SPAN_ON_CLOSE);
    /**
     * Return the currently active {@link Scope} which can be used to access the
     * currently active {@link Scope#getSpan()}.
     *
     * If there is an {@link Scope non-null scope}, its wrapped {@link Span} becomes an implicit parent
     * (as {@link References#CHILD_OF} reference) of any
     * newly-created {@link Span} at {@link Tracer.SpanBuilder#startActive(boolean)} or {@link SpanBuilder#start()}
     * time rather than at {@link Tracer#buildSpan(String)} time.
     *
     * @return Scope|null
     */
    public function getActive();
    /**
     * Closes all the current request root spans. Typically there only will be one.
     */
    public function close();
}
namespace DDTrace;

final class ScopeManager implements \DDTrace\Contracts\ScopeManager
{
    public function __construct(\DDTrace\SpanContext $rootContext = null)
    {
    }
    /**
     * {@inheritdoc}
     * @param Span|SpanInterface $span
     */
    public function activate(\DDTrace\Contracts\Span $span, $finishSpanOnClose = self::DEFAULT_FINISH_SPAN_ON_CLOSE)
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getActive()
    {
    }
    public function deactivate(\DDTrace\Scope $scope)
    {
    }
    /** @internal */
    public function getPrimaryRoot()
    {
    }
    /** @internal */
    public function getTopScope()
    {
    }
    /**
     * Closes all the current request root spans. Typically there only will be one.
     */
    public function close()
    {
    }
}
namespace DDTrace\Contracts;

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/Span.php
 */
interface Span
{
    /**
     * @return string
     */
    public function getOperationName();
    /**
     * Yields the SpanContext for this Span. Note that the return value of
     * Span::getContext() is still valid after a call to Span::finish(), as is
     * a call to Span::getContext() after a call to Span::finish().
     *
     * @return SpanContext
     */
    public function getContext();
    /**
     * Sets the end timestamp and finalizes Span state.
     *
     * With the exception of calls to getContext() (which are always allowed),
     * finish() must be the last call made to any span instance, and to do
     * otherwise leads to undefined behavior but not returning an exception.
     *
     * As an implementor, make sure you call {@see Tracer::deactivate()}
     * otherwise new spans might try to be child of this one.
     *
     * If the span is already finished, a warning should be logged.
     *
     * @param float|int|\DateTimeInterface|null $finishTime if passing float or int
     * it should represent the timestamp (including as many decimal places as you need)
     * @return void
     */
    public function finish($finishTime = null);
    /**
     * If the span is already finished, a warning should be logged.
     *
     * @param string $newOperationName
     */
    public function overwriteOperationName($newOperationName);
    /**
     * Sets the span's resource name.
     *
     * @param string $resource
     */
    public function setResource($resource);
    /**
     * Adds a tag to the span.
     *
     * If there is a pre-existing tag set for key, it is overwritten.
     *
     * As an implementor, consider using "standard tags" listed in {@see \DDTrace\Tags}
     *
     * If the span is already finished, a warning should be logged.
     *
     * @param string $key
     * @param mixed $value
     * @param boolean $setIfFinished
     * @return void
     */
    public function setTag($key, $value, $setIfFinished = false);
    /**
     * @param string $key
     * @return string|null
     */
    public function getTag($key);
    /**
     * Adds a log record to the span in key => value format, key must be a string and tag must be either
     * a string, a boolean value, or a numeric type.
     *
     * If the span is already finished, a warning should be logged.
     *
     * @param array $fields
     * @param int|float|\DateTimeInterface $timestamp
     * @return void
     */
    public function log(array $fields = [], $timestamp = null);
    /**
     * Adds a baggage item to the SpanContext which is immutable so it is required to use
     * SpanContext::withBaggageItem to get a new one.
     *
     * If the span is already finished, a warning should be logged.
     *
     * @param string $key
     * @param string $value
     * @return void
     */
    public function addBaggageItem($key, $value);
    /**
     * @param string $key
     * @return string|null returns null when there is not a item under the provided key
     */
    public function getBaggageItem($key);
    /**
     * @return array
     */
    public function getAllBaggageItems();
    /**
     * Stores a Throwable object within the span tags. The error status is
     * updated and the error.Error() string is included with a default tag key.
     * If the Span has been finished, it will not be modified by this method.
     *
     * @param \Throwable|\Exception|bool|null $error
     * @throws \InvalidArgumentException
     */
    public function setError($error);
    /**
     * Stores an error message and type in the Span.
     *
     * @param string $message
     * @param string $type
     */
    public function setRawError($message, $type);
    /**
     * Tells whether or not this Span contains errors.
     *
     * @return bool
     */
    public function hasError();
    /**
     * @return int
     */
    public function getStartTime();
    /**
     * @return int
     */
    public function getDuration();
    /**
     * @return string
     */
    public function getTraceId();
    /**
     * @return string
     */
    public function getSpanId();
    /**
     * @return null|string
     */
    public function getParentId();
    /**
     * @return string
     */
    public function getResource();
    /**
     * @return string
     */
    public function getService();
    /**
     * @return string|null
     */
    public function getType();
    /**
     * @return bool
     */
    public function isFinished();
    /**
     * @return array
     */
    public function getAllTags();
    /**
     * Tells whether or not the span has the provided tag. Note that there are no guarantees that the tag value is
     * not empty.
     *
     * @param string $name
     * @return bool
     */
    public function hasTag($name);
    /**
     * Set a DD metric.
     *
     * @param string $key
     * @param mixed $value
     */
    public function setMetric($key, $value);
    /**
     * @return array All the currently set metrics.
     */
    public function getMetrics();
}
namespace DDTrace\Data;

/**
 * Class Span
 * @property string $operationName
 * @property string $resource
 * @property string $service
 * @property string $type
 * @property int $startTime
 * @property int $duration
 * @property array $tags
 * @property array $metrics
 */
abstract class Span implements \DDTrace\Contracts\Span
{
    /**
     * @var SpanContextData
     */
    public $context;
    /**
     * @var bool
     */
    public $hasError = false;
    /**
     * @var \DDTrace\SpanData
     */
    protected $internalSpan;
    public function &__get($name)
    {
    }
    public function __set($name, $value)
    {
    }
    public function __isset($name)
    {
    }
}
namespace DDTrace;

class Span extends \DDTrace\Data\Span
{
    use \DDTrace\Log\LoggingTrait;
    public $internalSpan;
    /**
     * Span constructor.
     * @param SpanData $internalSpan
     * @param SpanContext $context
     */
    public function __construct(\DDTrace\SpanData $internalSpan, \DDTrace\SpanContext $context)
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getTraceId()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getSpanId()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getParentId()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function overwriteOperationName($operationName)
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getResource()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getService()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getType()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getStartTime()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getDuration()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function setTag($key, $value, $setIfFinished = false)
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getTag($key)
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getAllTags()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function hasTag($name)
    {
    }
    /**
     * @param string $key
     * @param mixed $value
     */
    public function setMetric($key, $value)
    {
    }
    /**
     * @return array
     */
    public function getMetrics()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function setResource($resource)
    {
    }
    /**
     * Stores a Throwable object within the span tags. The error status is
     * updated and the error.Error() string is included with a default tag key.
     * If the Span has been finished, it will not be modified by this method.
     *
     * @param Throwable|Exception|bool|null $error
     * @throws InvalidArgumentException
     */
    public function setError($error)
    {
    }
    /**
     * @param string $message
     * @param string $type
     */
    public function setRawError($message, $type)
    {
    }
    public function hasError()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function finish($finishTime = null)
    {
    }
    /**
     * {@inheritdoc}
     */
    public function isFinished()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getOperationName()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getContext()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function log(array $fields = [], $timestamp = null)
    {
    }
    /**
     * {@inheritdoc}
     */
    public function addBaggageItem($key, $value)
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getBaggageItem($key)
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getAllBaggageItems()
    {
    }
    public function __destruct()
    {
    }
}
namespace DDTrace\Contracts;

/**
 * SpanContext must be immutable in order to avoid complicated lifetime
 * issues around Span finish and references.
 *
 * Baggage items are key => value string pairs that apply to the given Span,
 * its SpanContext, and all Spans which directly or transitively reference
 * the local Span. That is, baggage items propagate in-band along with the
 * trace itself.
 */
interface SpanContext extends \IteratorAggregate
{
    /**
     * Returns the value of a baggage item based on its key. If there is no
     * value with such key it will return null.
     *
     * @param string $key
     * @return string|null
     */
    public function getBaggageItem($key);
    /**
     * Creates a new SpanContext out of the existing one and the new key => value pair.
     *
     * @param string $key
     * @param string $value
     * @return SpanContext
     */
    public function withBaggageItem($key, $value);
    /**
     * @return array
     */
    public function getAllBaggageItems();
    /**
     * Gets initial priority sampling, upon span creation
     *
     * @return int
     */
    public function getPropagatedPrioritySampling();
    /**
     * Sets initial priority sampling, to be consumed upon span creation
     *
     * @param int $propagatedPrioritySampling
     */
    public function setPropagatedPrioritySampling($propagatedPrioritySampling);
    /**
     * Returns whether or not this context represents the root span for a specific host.
     *
     * @return bool
     */
    public function isHostRoot();
    /**
     * @return string
     */
    public function getTraceId();
    /**
     * @return string
     */
    public function getSpanId();
    /**
     * @return string|null
     */
    public function getParentId();
    /**
     * @return bool
     */
    public function isDistributedTracingActivationContext();
}
namespace DDTrace\Data;

abstract class SpanContext implements \DDTrace\Contracts\SpanContext
{
    /**
     * The unique integer (63-bit unsigned) ID of the trace containing this span.
     * It is stored in decimal representation.
     *
     * @var string
     */
    public $traceId;
    /**
     * The span integer (63-bit unsigned) ID.
     * It is stored in devimal representation.
     *
     * @var string
     */
    public $spanId;
    /**
     * The span integer ID of the parent span.
     * It is stored in decimal representation.
     *
     * @var string|null
     */
    public $parentId;
    /**
     * Whether or not this SpanContext represent a distributed tracing remote context.
     * When the Tracer::extract() extracts a span context because of distributed tracing then this property is true,
     * otherwise is false.
     *
     * @var bool
     */
    public $isDistributedTracingActivationContext;
    /**
     * Initial priority sampling, upon span creation
     *
     * @var int
     */
    public $propagatedPrioritySampling;
    /**
     * The origin of the distributed trace.
     *
     * @var string|null
     */
    public $origin;
    /**
     * @var SpanContextInterface
     */
    public $parentContext;
    /**
     * @var array
     */
    public $baggageItems;
}
namespace DDTrace;

final class SpanContext extends \DDTrace\Data\SpanContext
{
    public function __construct($traceId, $spanId, $parentId = null, array $baggageItems = [], $isDistributedTracingActivationContext = false)
    {
    }
    public static function createAsChild(\DDTrace\Contracts\SpanContext $parentContext, $startTime = null)
    {
    }
    public static function createAsRoot(array $baggageItems = [], $startTime = null)
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getTraceId()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getSpanId()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getParentId()
    {
    }
    /**
     * {@inheritdoc}
     */
    #[\ReturnTypeWillChange]
    public function getIterator()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getPropagatedPrioritySampling()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function setPropagatedPrioritySampling($propagatedPrioritySampling)
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getBaggageItem($key)
    {
    }
    /**
     * {@inheritdoc}
     */
    public function withBaggageItem($key, $value)
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getAllBaggageItems()
    {
    }
    public function isEqual(\DDTrace\Contracts\SpanContext $spanContext)
    {
    }
    /**
     * {@inheritdoc}
     */
    public function isDistributedTracingActivationContext()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function isHostRoot()
    {
    }
}
/**
 * Although DataDog uses nanotime to report spans PHP does not support nanotime
 * plus, nanotime is a uint64 which is not supported either. Microtime will be used
 * and there will be transformations in reporting in order to send nanotime.
 */
class Time
{
    /**
     * @return int
     */
    public static function now()
    {
    }
    /**
     * @return int
     */
    public static function fromMicrotime($microtime)
    {
    }
    /**
     * @param mixed $time
     * @return bool
     */
    public static function isValid($time)
    {
    }
}
namespace DDTrace\Contracts;

interface Tracer
{
    /**
     * Checks if Tracer is in limited mode.
     *
     * Tracer needs to handle any operation even if its in limited mode,
     * however users can opt not to use tracer when its in limited mode.
     *
     * @return bool
     */
    public function limited();
    /**
     * Returns the current {@link ScopeManager}, which may be a noop but may not be null.
     *
     * @return ScopeManager
     */
    public function getScopeManager();
    /**
     * Returns the active {@link Span}. This is a shorthand for
     * Tracer::getScopeManager()->getActive()->getSpan(),
     * and null will be returned if {@link Scope#active()} is null.
     *
     * @return Span|null
     */
    public function getActiveSpan();
    /**
     * Starts a new span that is activated on a scope manager.
     *
     * It's also possible to not finish the {@see \DDTrace\Contracts\Span} when
     * {@see \DDTrace\Contracts\Scope} context expires:
     *
     *     $scope = $tracer->startActiveSpan('...', [
     *         'finish_span_on_close' => false,
     *     ]);
     *     $span = $scope->getSpan();
     *     try {
     *         $span->setTag(Tags\HTTP_METHOD, 'GET');
     *         // ...
     *     } finally {
     *         $scope->close();
     *     }
     *     // $span->finish() is not called as part of Scope deactivation as
     *     // finish_span_on_close is false
     *
     * @param string $operationName
     * @param array|StartSpanOptions $options Same as for startSpan() with
     *     aditional option of `finish_span_on_close` that enables finishing
     *     of span whenever a scope is closed. It is true by default.
     *
     * @return Scope A Scope that holds newly created Span and is activated on
     *               a ScopeManager.
     */
    public function startActiveSpan($operationName, $options = []);
    /**
     * Starts and returns a new span representing a unit of work.
     *
     * Whenever `child_of` reference is not passed then
     * {@see \DDTrace\Contracts\ScopeManager::getActive()} span is used as `child_of`
     * reference. In order to ignore implicit parent span pass in
     * `ignore_active_span` option set to true.
     *
     * Starting a span with explicit parent:
     *
     *     $tracer->startSpan('...', [
     *         'child_of' => $parentSpan,
     *     ]);
     *
     * @see \DDTrace\StartSpanOptions
     *
     * @param string $operationName
     * @param array|StartSpanOptions $options See StartSpanOptions for
     *                                        available options.
     *
     * @return Span
     *
     * @throws InvalidSpanOption for invalid option
     * @throws InvalidReferencesSet for invalid references set
     */
    public function startSpan($operationName, $options = []);
    /**
     * @param SpanContext $spanContext
     * @param string $format
     * @param mixed $carrier
     *
     * @see Formats
     *
     * @throws UnsupportedFormat when the format is not recognized by the tracer
     * implementation
     */
    public function inject(\DDTrace\Contracts\SpanContext $spanContext, $format, &$carrier);
    /**
     * @param string $format
     * @param mixed $carrier
     * @return SpanContext|null
     *
     * @see Formats
     *
     * @throws UnsupportedFormat when the format is not recognized by the tracer
     * implementation
     */
    public function extract($format, $carrier);
    /**
     * Allow tracer to send span data to be instrumented.
     *
     * This method might not be needed depending on the tracing implementation
     * but one should make sure this method is called after the request is delivered
     * to the client.
     *
     * As an implementor, a good idea would be to use {@see register_shutdown_function}
     * or {@see fastcgi_finish_request} in order to not to delay the end of the request
     * to the client.
     */
    public function flush();
    /**
     * @param mixed $prioritySampling
     */
    public function setPrioritySampling($prioritySampling);
    /**
     * @return int|null
     */
    public function getPrioritySampling();
    /**
     * This behaves just like Tracer::startActiveSpan(), but it saves the Scope instance
     * on the tracer to be accessed later by Tracer::getRootScope().
     *
     * @param string $operationName
     * @param array $options
     * @return Scope
     */
    public function startRootSpan($operationName, $options = []);
    /**
     * @return Scope|null
     */
    public function getRootScope();
    /**
     * Returns the root span or null and never throws an exception.
     *
     * @return Span|null
     */
    public function getSafeRootSpan();
    /**
     * Returns the entire trace encoded as a plain-old PHP array.
     *
     * @return array
     */
    public function getTracesAsArray();
    /**
     * Returns the count of currently stored traces
     *
     * @return int
     */
    public function getTracesCount();
}
namespace DDTrace;

final class Tracer implements \DDTrace\Contracts\Tracer
{
    use \DDTrace\Log\LoggingTrait;
    /**
     * @param Transport $transport
     * @param Propagator[] $propagators
     * @param array $config
     */
    public function __construct(\DDTrace\Transport $transport = null, array $propagators = null, array $config = [])
    {
    }
    public function limited()
    {
    }
    /**
     * Resets this tracer to its original state.
     */
    public function reset()
    {
    }
    /**
     * @return Tracer
     */
    public static function noop()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function startSpan($operationName, $options = [])
    {
    }
    /**
     * {@inheritdoc}
     */
    public function startRootSpan($operationName, $options = [])
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getRootScope()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function startActiveSpan($operationName, $options = [])
    {
    }
    /**
     * {@inheritdoc}
     */
    public function inject(\DDTrace\Contracts\SpanContext $spanContext, $format, &$carrier)
    {
    }
    /**
     * {@inheritdoc}
     */
    public function extract($format, $carrier)
    {
    }
    /**
     * @return void
     */
    public function flush()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getScopeManager()
    {
    }
    /**
     * @return null|Span
     */
    public function getActiveSpan()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getTracesAsArray()
    {
    }
    public function addUrlAsResourceNameToSpan(\DDTrace\Contracts\Span $span)
    {
    }
    /**
     * @param mixed $prioritySampling
     */
    public function setPrioritySampling($prioritySampling)
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getPrioritySampling()
    {
    }
    /**
     * Returns the root span or null and never throws an exception.
     *
     * @return SpanInterface|null
     */
    public function getSafeRootSpan()
    {
    }
    /**
     * @return string
     */
    public static function version()
    {
    }
    /**
     * {@inheritdoc}
     */
    public function getTracesCount()
    {
    }
}
interface Transport
{
    /**
     * @param TracerInterface $tracer
     */
    public function send(\DDTrace\Contracts\Tracer $tracer);
    /**
     * @param string $key
     * @param string $value
     * @return void
     */
    public function setHeader($key, $value);
}
namespace DDTrace\Transport;

final class Internal implements \DDTrace\Transport
{
    public function send(\DDTrace\Contracts\Tracer $tracer)
    {
    }
    public function setHeader($key, $value)
    {
    }
}
namespace DDTrace\Exceptions;

/**
 * Thrown when passing an invalid argument for a reference
 */
final class InvalidReferenceArgument extends \InvalidArgumentException
{
    /**
     * @return InvalidReferenceArgument
     */
    public static function forEmptyType()
    {
    }
    /**
     * @param mixed $context
     * @return InvalidReferenceArgument
     */
    public static function forInvalidContext($context)
    {
    }
}
/**
 * Thrown when a reference has more than one parent in the SpanOptions
 */
final class InvalidReferencesSet extends \DomainException
{
    /**
     * @param string $message
     * @return InvalidReferencesSet
     */
    public static function create($message)
    {
    }
    /**
     * @return InvalidReferencesSet
     */
    public static function forMoreThanOneParent()
    {
    }
}
final class InvalidSpanArgument extends \InvalidArgumentException
{
    public static function forTagKey($key)
    {
    }
    public static function forError($error)
    {
    }
}
/**
 * Thrown when passing an invalid option on Span creation
 */
final class InvalidSpanOption extends \InvalidArgumentException
{
    /**
     * @return InvalidSpanOption
     */
    public static function forIncludingBothChildOfAndReferences()
    {
    }
    /**
     * @param mixed $reference
     * @return InvalidSpanOption
     */
    public static function forInvalidReference($reference)
    {
    }
    /**
     * @return InvalidSpanOption
     */
    public static function forInvalidStartTime()
    {
    }
    public static function forInvalidChildOf($childOfOption)
    {
    }
    /**
     * @param string $key
     * @return InvalidSpanOption
     */
    public static function forUnknownOption($key)
    {
    }
    /**
     * @param mixed $tag
     * @return InvalidSpanOption
     */
    public static function forInvalidTag($tag)
    {
    }
    /**
     * @param mixed $tagValue
     * @return InvalidSpanOption
     */
    public static function forInvalidTagValue($tagValue)
    {
    }
    /**
     * @param mixed $value
     * @return InvalidSpanOption
     */
    public static function forInvalidTags($value)
    {
    }
    /**
     * @param mixed $value
     * @return InvalidSpanOption
     */
    public static function forInvalidReferenceSet($value)
    {
    }
    /**
     * @param mixed $value
     * @return InvalidSpanOption
     */
    public static function forFinishSpanOnClose($value)
    {
    }
    /**
     * @param mixed $value
     * @return InvalidSpanOption
     */
    public static function forIgnoreActiveSpan($value)
    {
    }
}
/**
 * Thrown when trying to inject or extract in an invalid format
 */
final class UnsupportedFormat extends \UnexpectedValueException
{
    /**
     * @param string $format
     * @return UnsupportedFormat
     */
    public static function forFormat($format)
    {
    }
}
namespace DDTrace;

class Format
{
    /**
     * Used a (single) arbitrary binary blob representing a SpanContext
     *
     * For both Tracer::inject() and Tracer::extract() the carrier must be a `string`.
     */
    const BINARY = 'binary';
    /**
     * Used for an arbitrary string-to-string map with an unrestricted character set for both keys and values
     *
     * Unlike `HTTP_HEADERS`, the `TEXT_MAP` format does not restrict the key or
     * value character sets in any way.
     *
     * For both Tracer::inject() and Tracer::extract() the carrier must be a `array|ArrayObject`.
     */
    const TEXT_MAP = 'text_map';
    /**
     * Used for a string-to-string map with keys and values that are suitable for use in HTTP headers (a la RFC 7230.
     * In practice, since there is such "diversity" in the way that HTTP headers are treated in the wild, it is strongly
     * recommended that Tracer implementations use a limited HTTP header key space and escape values conservatively.
     *
     * Unlike `TEXT_MAP`, the `HTTP_HEADERS` format requires that the keys and values be valid as HTTP headers as-is
     * (i.e., character casing may be unstable and special characters are disallowed in keys, values should be
     * URL-escaped, etc).
     *
     * For both Tracer::inject() and Tracer::extract() the carrier must be a `array|ArrayObject`.
     *
     * For example, Tracer::inject():
     *
     *    $headers = []
     *    $tracer->inject($span->getContext(), Format::HTTP_HEADERS, $headers)
     *    $request = new GuzzleHttp\Psr7\Request($uri, $body, $headers);
     *
     * Or Tracer::extract():
     *
     *    $headers = $request->getHeaders()
     *    $clientContext = $tracer->extract(Formats::HTTP_HEADERS, $headers)
     *
     * @see http://www.php-fig.org/psr/psr-7/#12-http-headers
     * @see http://php.net/manual/en/function.getallheaders.php
     */
    const HTTP_HEADERS = 'http_headers';
}
final class GlobalTracer
{
    /**
     * GlobalTracer::set sets the [singleton] Tracer returned by get().
     * Those who use GlobalTracer (rather than directly manage a Tracer instance)
     * should call GlobalTracer::set as early as possible in bootstrap, prior to
     * start a new span. Prior to calling GlobalTracer::set, any Spans started
     * via the `Tracer::startActiveSpan` (etc) globals are noops.
     *
     * @param TracerInterface $tracer
     */
    public static function set(\DDTrace\Contracts\Tracer $tracer)
    {
    }
    /**
     * GlobalTracer::get returns the global singleton `Tracer` implementation.
     * Before `GlobalTracer::set` is called, the `GlobalTracer::get` is a noop
     * implementation that drops all data handed to it.
     *
     * @return TracerInterface
     */
    public static function get()
    {
    }
}
namespace DDTrace\Http;

/**
 * A utility class that provides methods to work on urls
 */
class Urls
{
    /**
     * Inject URL replacement patterns using '*' and '$*' as wildcards
     * The '*' wildcard will match one or more characters to be replaced with '?'
     * The '$*' wildcard will match one or more characters without replacement
     *
     * @param string[] $patternsWithWildcards
     */
    public function __construct(array $patternsWithWildcards = [])
    {
    }
    /**
     * Removes query string and fragment and user information from a url.
     *
     * @param string $url
     * @param bool $dropUserInfo Optional. If `true`, removes the user information fragment instead of obfuscating it.
     *                           Defaults to `false`.
     */
    public static function sanitize($url, $dropUserInfo = false)
    {
    }
    /**
     * Extracts the hostname of a given URL
     *
     * @param string $url
     * @return string
     */
    public static function hostname($url)
    {
    }
    /**
     * Metadata keys must start with [a-zA-Z:] so IP addresses,
     * for example, need to be prefixed with a valid character.
     *
     * Note: then name of this function is misleading, as it should actually be normalizeUrlForService(), but since this
     * part of the public API, we keep it like this and discuss a future deprecation.
     *
     * @param string $url
     * @return string
     */
    public static function hostnameForTag($url)
    {
    }
    /**
     * Reduces cardinality of a url.
     *
     * @param string $url
     * @return string
     */
    public function normalize($url)
    {
    }
}
namespace DDTrace\Log;

/**
 * Defines logging methods as used in DDTrace code.
 */
interface LoggerInterface
{
    /**
     * Logs a message at the debug level.
     *
     * @param string $message
     * @param array  $context
     *
     * @return void
     */
    public function debug($message, array $context = array());
    /**
     * Logs a warning at the debug level.
     *
     * @param string $message
     * @param array  $context
     *
     * @return void
     */
    public function warning($message, array $context = []);
    /**
     * Logs a error at the debug level.
     *
     * @param string $message
     * @param array  $context
     *
     * @return void
     */
    public function error($message, array $context = array());
    /**
     * @param string $level
     * @return bool
     */
    public function isLevelActive($level);
}
/**
 * An abstract logger.
 */
abstract class AbstractLogger implements \DDTrace\Log\LoggerInterface
{
    /**
     * @param string $level
     */
    public function __construct($level)
    {
    }
    /**
     * @param string $level
     * @return bool
     */
    public function isLevelActive($level)
    {
    }
}
/**
 * Provides methods to interpolate log messages.
 */
trait InterpolateTrait
{
    /**
     * Interpolates context values into the message placeholders. Example code from:
     * https://www.php-fig.org/psr/psr-3/
     *
     * @param string $message
     * @param array $context
     * @return string
     */
    public static function interpolate($message, array $context = [])
    {
    }
}
/**
 * JSON logger that writes to a stream, with simple logs correlation support.
 * Heavily inspired from Monolog's StreamHandler.
 * @internal This logger is internal and can be removed without prior notice
 */
final class DatadogLogger
{
    use \DDTrace\Log\InterpolateTrait;
    const DEFAULT_JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR;
    public function __construct($stream = null, $mode = 'a')
    {
    }
    /**
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function emergency($message, array $context = [])
    {
    }
    /**
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function alert($message, array $context = [])
    {
    }
    /**
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function critical($message, array $context = [])
    {
    }
    /**
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function error($message, array $context = [])
    {
    }
    /**
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function warning($message, array $context = [])
    {
    }
    /**
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function notice($message, array $context = [])
    {
    }
    /**
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function info($message, array $context = [])
    {
    }
    /**
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function debug($message, array $context = [])
    {
    }
    /**
     * @param string $level
     * @param string|\Stringable $message
     * @param array $context
     * @return void
     */
    public function log(string $level, $message, array $context = [])
    {
    }
    public function customErrorHandler(int $code, string $msg) : bool
    {
    }
}
/**
 * An implementation of the DDTrace\LoggerInterface that logs to the error_log.
 */
class ErrorLogLogger extends \DDTrace\Log\AbstractLogger
{
    use \DDTrace\Log\InterpolateTrait;
    /**
     * Logs a debug message. Substitution is provided as specified in:
     * https://www.php-fig.org/psr/psr-3/
     *
     * @param string $message
     * @param array $context
     */
    public function debug($message, array $context = [])
    {
    }
    /**
     * Logs a warning at the debug level.
     *
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function warning($message, array $context = [])
    {
    }
    /**
     * Logs a error at the debug level.
     *
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function error($message, array $context = [])
    {
    }
}
/**
 * Known log levels.
 */
final class LogLevel
{
    /**
     * Const list from https://www.php-fig.org/psr/psr-3/
     */
    const EMERGENCY = 'emergency';
    const ALERT = 'alert';
    const CRITICAL = 'critical';
    const ERROR = 'error';
    const WARNING = 'warning';
    const NOTICE = 'notice';
    const INFO = 'info';
    const DEBUG = 'debug';
    /**
     * All the log levels.
     *
     * @return string[]
     */
    public static function all()
    {
    }
}
/**
 * A global logger holder. Can be configured to use a specific logger. If not configured, it returns a NullLogger.
 */
final class Logger
{
    /**
     * Sets the global logger instance.
     *
     * @param LoggerInterface $logger
     */
    public static function set(\DDTrace\Log\LoggerInterface $logger)
    {
    }
    /**
     * Retrieves the global logger instance. If not set, it falls back to a NullLogger.
     *
     * @return LoggerInterface
     */
    public static function get()
    {
    }
    /**
     * Reset the logger.
     */
    public static function reset()
    {
    }
}
/**
 * An implementation of the DDTrace\LoggerInterface that logs nothing.
 */
final class NullLogger extends \DDTrace\Log\AbstractLogger
{
    /**
     * Logs a debug at the debug level.
     *
     * @param string $message
     * @param array $context
     */
    public function debug($message, array $context = array())
    {
    }
    /**
     * Logs a warning at the debug level.
     *
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function warning($message, array $context = [])
    {
    }
    /**
     * Logs a error at the debug level.
     *
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    public function error($message, array $context = array())
    {
    }
    /**
     * @param string $level
     * @return bool
     */
    public function isLevelActive($level)
    {
    }
}
namespace DDTrace\Obfuscation;

/**
 * Converts strings with wildcards into search/replace regex arrays
 * The following is converted
 * - * -> ?
 * - $* -> ${n}
 */
// Examples: /api/v1/users/*,/api/v1/rooms/*/$*,/api/v1/bookings/*/guests
// - /api/v1/users/123 -> /api/v1/users/?
// - /api/v1/rooms/123/details -> /api/v1/rooms/?/details
// - /api/v1/rooms/foo-bar-room/gallery -> /api/v1/rooms/?/gallery
// - /api/v1/bookings/123/guests/ -> /api/v1/bookings/?/guests
final class WildcardToRegex
{
    const REPLACEMENT_CHARACTER = '?';
    /**
     * @param string $wildcardPattern
     *
     * @return string[]
     */
    public static function convert($wildcardPattern)
    {
    }
}
namespace DDTrace;

final class Reference
{
    /**
     * A Span may be the ChildOf a parent Span. In a ChildOf reference,
     * the parent Span depends on the child Span in some capacity.
     */
    const CHILD_OF = 'child_of';
    /**
     * Some parent Spans do not depend in any way on the result of their
     * child Spans. In these cases, we say merely that the child Span
     * FollowsFrom the parent Span in a causal sense.
     */
    const FOLLOWS_FROM = 'follows_from';
    /**
     * @param SpanContextInterface|SpanInterface $context
     * @param string $type
     * @throws InvalidReferenceArgument on empty type
     * @return Reference when context is invalid
     */
    public static function create($type, $context)
    {
    }
    /**
     * @return SpanContextInterface
     */
    public function getContext()
    {
    }
    /**
     * Checks whether a Reference is of one type.
     *
     * @param string $type the type for the reference
     * @return bool
     */
    public function isType($type)
    {
    }
}
namespace DDTrace\Sampling;

class PrioritySampling
{
    // The Agent will drop the trace, as instructed by any mechanism that is not the sampler.
    const USER_REJECT = -1;
    // Automatic sampling decision. The Agent should drop the trace.
    const AUTO_REJECT = 0;
    // Automatic sampling decision. The Agent should keep the trace.
    const AUTO_KEEP = 1;
    // The Agent should keep the trace, as instructed by any mechanism that is not the sampler.
    // The backend will only apply sampling if above maximum volume allowed.
    const USER_KEEP = 2;
    // It was not possible to parse
    const UNKNOWN = null;
    /**
     * @param mixed|string $value
     * @return int|null
     */
    public static function parse($value)
    {
    }
}
namespace DDTrace;

final class StartSpanOptions
{
    /**
     * @param array $options
     * @throws InvalidSpanOption when one of the options is invalid
     * @throws InvalidReferencesSet when there are inconsistencies about the references
     * @return StartSpanOptions
     */
    public static function create(array $options)
    {
    }
    /**
     * @param SpanInterface|SpanContextInterface $parent
     * @return StartSpanOptions
     */
    public function withParent($parent)
    {
    }
    /**
     * @return Reference[]
     */
    public function getReferences()
    {
    }
    /**
     * @return array
     */
    public function getTags()
    {
    }
    /**
     * @return int|float|\DateTime|null if returning float or int it should represent
     * the timestamp (including as many decimal places as you need)
     */
    public function getStartTime()
    {
    }
    /**
     * @return bool
     */
    public function shouldFinishSpanOnClose()
    {
    }
    /**
     * @return bool
     */
    public function shouldIgnoreActiveSpan()
    {
    }
}
class Tag
{
    // Generic
    const ENV = 'env';
    const SPAN_TYPE = 'span.type';
    const SPAN_KIND = 'span.kind';
    const SPAN_KIND_VALUE_SERVER = 'server';
    const SPAN_KIND_VALUE_CLIENT = 'client';
    const SPAN_KIND_VALUE_PRODUCER = 'producer';
    const SPAN_KIND_VALUE_CONSUMER = 'consumer';
    const SPAN_KIND_VALUE_INTERNAL = 'internal';
    const COMPONENT = 'component';
    const SERVICE_NAME = 'service.name';
    const MANUAL_KEEP = 'manual.keep';
    const MANUAL_DROP = 'manual.drop';
    const PID = 'process_id';
    const RESOURCE_NAME = 'resource.name';
    const DB_STATEMENT = 'sql.query';
    const ERROR = 'error';
    const ERROR_MSG = 'error.message';
    // string representing the error message
    const ERROR_TYPE = 'error.type';
    // string representing the type of the error
    const ERROR_STACK = 'error.stack';
    // human readable version of the stack
    const HTTP_METHOD = 'http.method';
    const HTTP_ROUTE = 'http.route';
    const HTTP_STATUS_CODE = 'http.status_code';
    const HTTP_URL = 'http.url';
    const HTTP_VERSION = 'http.version';
    const LOG_EVENT = 'event';
    const LOG_ERROR = 'error';
    const LOG_ERROR_OBJECT = 'error.object';
    const LOG_MESSAGE = 'message';
    const LOG_STACK = 'stack';
    const NETWORK_DESTINATION_NAME = 'network.destination.name';
    const TARGET_HOST = 'out.host';
    const TARGET_PORT = 'out.port';
    const BYTES_OUT = 'net.out.bytes';
    const ANALYTICS_KEY = '_dd1.sr.eausr';
    const HOSTNAME = '_dd.hostname';
    const ORIGIN = '_dd.origin';
    const VERSION = 'version';
    const SERVICE_VERSION = 'service.version';
    // OpenTelemetry compatible tag
    // Elasticsearch
    const ELASTICSEARCH_BODY = 'elasticsearch.body';
    const ELASTICSEARCH_METHOD = 'elasticsearch.method';
    const ELASTICSEARCH_PARAMS = 'elasticsearch.params';
    const ELASTICSEARCH_URL = 'elasticsearch.url';
    // Database
    const DB_NAME = 'db.name';
    const DB_CHARSET = 'db.charset';
    const DB_INSTANCE = 'db.instance';
    const DB_TYPE = 'db.type';
    const DB_SYSTEM = 'db.system';
    const DB_ROW_COUNT = 'db.row_count';
    const DB_STMT = 'db.statement';
    const DB_USER = 'db.user';
    // Laravel Queue
    const LARAVELQ_ATTEMPTS = 'messaging.laravel.attempts';
    const LARAVELQ_BATCH_ID = 'messaging.laravel.batch_id';
    const LARAVELQ_CONNECTION = 'messaging.laravel.connection';
    const LARAVELQ_MAX_TRIES = 'messaging.laravel.max_tries';
    const LARAVELQ_NAME = 'messaging.laravel.name';
    const LARAVELQ_TIMEOUT = 'messaging.laravel.timeout';
    // MongoDB
    const MONGODB_BSON_ID = 'mongodb.bson.id';
    const MONGODB_COLLECTION = 'mongodb.collection';
    const MONGODB_DATABASE = 'mongodb.db';
    const MONGODB_PROFILING_LEVEL = 'mongodb.profiling_level';
    const MONGODB_READ_PREFERENCE = 'mongodb.read_preference';
    const MONGODB_SERVER = 'mongodb.server';
    const MONGODB_TIMEOUT = 'mongodb.timeout';
    const MONGODB_QUERY = 'mongodb.query';
    // REDIS
    const REDIS_RAW_COMMAND = 'redis.raw_command';
    // Message Queue
    const MQ_SYSTEM = 'messaging.system';
    const MQ_DESTINATION = 'messaging.destination';
    const MQ_DESTINATION_KIND = 'messaging.destination_kind';
    const MQ_TEMP_DESTINATION = 'messaging.temp_destination';
    const MQ_PROTOCOL = 'messaging.protocol';
    const MQ_PROTOCOL_VERSION = 'messaging.protocol_version';
    const MQ_URL = 'messaging.url';
    const MQ_MESSAGE_ID = 'messaging.message_id';
    const MQ_CONVERSATION_ID = 'messaging.conversation_id';
    const MQ_MESSAGE_PAYLOAD_SIZE = 'messaging.message_payload_size_bytes';
    const MQ_OPERATION = 'messaging.operation';
    const MQ_CONSUMER_ID = 'messaging.consumer_id';
    // RabbitMQ
    const RABBITMQ_DELIVERY_MODE = 'messaging.rabbitmq.delivery_mode';
    const RABBITMQ_EXCHANGE = 'messaging.rabbitmq.exchange';
    const RABBITMQ_ROUTING_KEY = 'messaging.rabbitmq.routing_key';
    // Exec
    const EXEC_CMDLINE_EXEC = 'cmd.exec';
    const EXEC_CMDLINE_SHELL = 'cmd.shell';
    const EXEC_TRUNCATED = 'cmd.truncated';
    const EXEC_EXIT_CODE = 'cmd.exit_code';
}
class Type
{
    const CACHE = 'cache';
    const HTTP_CLIENT = 'http';
    const WEB_SERVLET = 'web';
    const CLI = 'cli';
    const SQL = 'sql';
    const MESSAGE_CONSUMER = 'queue';
    const MESSAGE_PRODUCER = 'queue';
    const CASSANDRA = 'cassandra';
    const ELASTICSEARCH = 'elasticsearch';
    const MEMCACHED = 'memcached';
    const MONGO = 'mongodb';
    const OPENAI = 'openai';
    const REDIS = 'redis';
    const SYSTEM = 'system';
}