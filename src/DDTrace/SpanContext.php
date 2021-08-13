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
        // Since dd_trace_push_span_id() updates the return value of
        // dd_trace_peek_span_id(), we need to access the existing
        // value before generating a new ID
        $activeSpanId = dd_trace_peek_span_id();

        if (PHP_VERSION_ID < 70000) {
            $instance = new self(
                $parentContext->getTraceId(),
                dd_trace_push_span_id(),
                // Since the last span could have been generated internally,
                // we can't use `$parentContext->getSpanId()` here
                $activeSpanId,
                $parentContext->getAllBaggageItems(),
                false
            );
        } else {
            // @phpstan-ignore-next-line
            if (!$parentContext->isDistributedTracingActivationContext() || !active_span()) {
                if ($startTime) {
                    // @phpstan-ignore-next-line
                    start_span($startTime);
                } else {
                    // @phpstan-ignore-next-line
                    start_span(); // we'll peek at the span stack top later
                }
            }
            $instance = new self(
                $parentContext->getTraceId(),
                \dd_trace_peek_span_id(),
                // Since the last span could have been generated internally,
                // we can't use `$parentContext->getSpanId()` here
                $activeSpanId,
                $parentContext->getAllBaggageItems(),
                false
            );
        }
        $instance->parentContext = $parentContext;
        $instance->setPropagatedPrioritySampling($parentContext->getPropagatedPrioritySampling());
        if (
            property_exists($instance, 'origin')
            && !empty($parentContext->origin)
        ) {
            $instance->origin = $parentContext->origin;
        }
        return $instance;
    }

    public static function createAsRoot(array $baggageItems = [])
    {
        // with peek the current span id for the existing root span

        if (PHP_VERSION_ID >= 70000) {
            // @phpstan-ignore-next-line
            if (!active_span()) {
                // @phpstan-ignore-next-line
                start_span();
            }
            $nextId = \dd_trace_peek_span_id();
        } else {
            $nextId = \dd_trace_push_span_id();
        }

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
