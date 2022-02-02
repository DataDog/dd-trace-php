<?php

namespace DDTrace;

use ArrayIterator;
use DDTrace\Contracts\SpanContext as SpanContextInterface;
use DDTrace\Data\SpanContext as SpanContextData;

final class SpanContext extends SpanContextData
{
    public function __construct(
        $traceId,
        $spanId,
        $parentId = null,
        array $baggageItems = [],
        $isDistributedTracingActivationContext = false
    ) {
        $this->traceId = $traceId;
        $this->spanId = $spanId;
        $this->parentId = $parentId ?: null;
        $this->baggageItems = $baggageItems;
        $this->isDistributedTracingActivationContext = $isDistributedTracingActivationContext;
    }

    public static function createAsChild(SpanContextInterface $parentContext, $startTime = null)
    {
        // @phpstan-ignore-next-line
        $origin = \property_exists($parentContext, 'origin') ? $parentContext->origin : null;
        if (!$parentContext->isDistributedTracingActivationContext() || !active_span()) {
            if ($parentContext->isDistributedTracingActivationContext()) {
                set_distributed_tracing_context(
                    $parentContext->getTraceId(),
                    $parentContext->getSpanId(),
                    $origin
                );
            }
            if ($startTime) {
                start_span($startTime);
            } else {
                start_span(); // we'll peek at the span stack top later
            }
        }
        $instance = new self(
            $parentContext->getTraceId(),
            active_span()->id,
            $parentContext->getSpanId(),
            $parentContext->getAllBaggageItems(),
            false
        );
        $instance->parentContext = $parentContext;
        $instance->setPropagatedPrioritySampling($parentContext->getPropagatedPrioritySampling());
        if ($origin) {
            $instance->origin = $origin;
        }
        return $instance;
    }

    public static function createAsRoot(array $baggageItems = [], $startTime = null)
    {
        // with peek the current span id for the existing root span
        if (!active_span()) {
            if ($startTime) {
                start_span($startTime);
            } else {
                start_span(); // we'll peek at the span stack top later
            }
        }
        $nextId = active_span()->id;

        return new self(
            $nextId,
            $nextId,
            null,
            $baggageItems,
            false
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getTraceId()
    {
        return $this->traceId;
    }

    /**
     * {@inheritdoc}
     */
    public function getSpanId()
    {
        return $this->spanId;
    }

    /**
     * {@inheritdoc}
     */
    public function getParentId()
    {
        return $this->parentId;
    }

    /**
     * {@inheritdoc}
     */
    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        return new ArrayIterator($this->baggageItems);
    }

    /**
     * {@inheritdoc}
     */
    public function getPropagatedPrioritySampling()
    {
        return $this->propagatedPrioritySampling;
    }

    /**
     * {@inheritdoc}
     */
    public function setPropagatedPrioritySampling($propagatedPrioritySampling)
    {
        $this->propagatedPrioritySampling = $propagatedPrioritySampling;
    }

    /**
     * {@inheritdoc}
     */
    public function getBaggageItem($key)
    {
        return array_key_exists($key, $this->baggageItems)
            ? $this->baggageItems[$key]
            : null;
    }

    /**
     * {@inheritdoc}
     */
    public function withBaggageItem($key, $value)
    {
        return new self(
            $this->traceId,
            $this->spanId,
            $this->parentId,
            array_merge($this->baggageItems, [$key => $value])
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getAllBaggageItems()
    {
        return $this->baggageItems;
    }

    public function isEqual(SpanContextInterface $spanContext)
    {
        if ($spanContext instanceof SpanContextData) {
            return $this->traceId === $spanContext->traceId
                && $this->spanId === $spanContext->spanId
                && $this->parentId === $spanContext->parentId
                && $this->baggageItems === $spanContext->baggageItems;
        }

        return
            $this->traceId === $spanContext->getTraceId()
            && $this->spanId === $spanContext->getSpanId()
            && $this->parentId === $spanContext->getParentId()
            && $this->baggageItems === $spanContext->getAllBaggageItems();
    }

    /**
     * {@inheritdoc}
     */
    public function isDistributedTracingActivationContext()
    {
        return $this->isDistributedTracingActivationContext;
    }

    /**
     * {@inheritdoc}
     */
    public function isHostRoot()
    {
        return $this->parentContext === null || $this->parentContext->isDistributedTracingActivationContext();
    }
}
