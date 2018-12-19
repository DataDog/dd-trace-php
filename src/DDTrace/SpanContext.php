<?php

namespace DDTrace;

use ArrayIterator;
use DDTrace\Contracts\SpanContext as SpanContextInterface;

final class SpanContext implements SpanContextInterface
{
    /**
     * The unique integer (64-bit unsigned) ID of the trace containing this span.
     * It is stored in hexadecimal representation.
     *
     * @var string
     */
    private $traceId;

    /**
     * The span integer (64-bit unsigned) ID.
     * It is stored in hexadecimal representation.
     *
     * @var string
     */
    private $spanId;

    /**
     * The span integer ID of the parent span.
     * It is stored in hexadecimal representation.
     *
     * @var string|null
     */
    private $parentId;

    private $baggageItems;

    /**
     * Whether or not this SpanContext represent a distributed tracing remote context.
     * When the Tracer::extract() extracts a span context because of distributed tracing then this property is true,
     * otherwise is false.
     *
     * @var bool
     */
    private $isDistributedTracingActivationContext;

    /**
     * @var int
     */
    private $propagatedPrioritySampling;

    /**
     * @var SpanContext
     */
    private $parentContext;

    public function __construct(
        $traceId,
        $spanId,
        $parentId = null,
        array $baggageItems = [],
        $isDistributedTracingActivationContext = false
    ) {
        $this->traceId = $traceId;
        $this->spanId = $spanId;
        $this->parentId = $parentId;
        $this->baggageItems = $baggageItems;
        $this->isDistributedTracingActivationContext = $isDistributedTracingActivationContext;
    }

    public static function createAsChild(SpanContext $parentContext)
    {
        $instance = new self(
            $parentContext->traceId,
            self::nextId(),
            $parentContext->spanId,
            $parentContext->baggageItems,
            false
        );
        $instance->parentContext = $parentContext;
        $instance->setPropagatedPrioritySampling($parentContext->propagatedPrioritySampling);
        return $instance;
    }

    public static function createAsRoot(array $baggageItems = [])
    {
        $nextId = self::nextId();

        return new self(
            $nextId,
            $nextId,
            null,
            $baggageItems,
            false
        );
    }

    public function getTraceId()
    {
        return $this->traceId;
    }

    public function getSpanId()
    {
        return $this->spanId;
    }

    public function getParentId()
    {
        return $this->parentId;
    }

    public function getIterator()
    {
        return new ArrayIterator($this->baggageItems);
    }

    /**
     * @return int
     */
    public function getPropagatedPrioritySampling()
    {
        return $this->propagatedPrioritySampling;
    }

    /**
     * @param int $propagatedPrioritySampling
     */
    public function setPropagatedPrioritySampling($propagatedPrioritySampling)
    {
        $this->propagatedPrioritySampling = $propagatedPrioritySampling;
    }

    public function getBaggageItem($key)
    {
        return array_key_exists($key, $this->baggageItems)
            ? $this->baggageItems[$key]
            : null;
    }

    public function withBaggageItem($key, $value)
    {
        return new self(
            $this->traceId,
            $this->spanId,
            $this->parentId,
            array_merge($this->baggageItems, [$key => $value])
        );
    }

    public function isEqual(SpanContext $spanContext)
    {
        return
            $this->traceId === $spanContext->traceId
            && $this->spanId === $spanContext->spanId
            && $this->parentId === $spanContext->parentId
            && $this->baggageItems === $spanContext->baggageItems;
    }

    private static function nextId()
    {
        /*
         * Trace and span ID's need to be unsigned-63-bit-int strings in
         * order to work well with other APM integrations. Since the tracer
         * is not in a cryptographic context, we don't need to use PHP's
         * CSPRNG random_bytes(); instead the more performant mt_rand()
         * will do. And since all integers in PHP are signed, an int
         * between 1 & PHP_INT_MAX will be 63-bit.
         */
        return (string) mt_rand(1, PHP_INT_MAX);
    }

    /**
     * @return bool
     */
    public function isDistributedTracingActivationContext()
    {
        return $this->isDistributedTracingActivationContext;
    }

    /**
     * Returns whether or not this context represents the root span for a specific host.
     *
     * @return bool
     */
    public function isHostRoot()
    {
        return $this->parentContext === null || $this->parentContext->isDistributedTracingActivationContext();
    }
}
