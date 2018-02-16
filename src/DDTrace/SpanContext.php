<?php

namespace DDTrace;

use ArrayIterator;
use OpenTracing\SpanContext as OpenTracingSpanContext;

final class SpanContext implements OpenTracingSpanContext
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

    public function __construct($traceId, $spanId, $parentId = null, array $baggageItems = [])
    {
        $this->traceId = $traceId;
        $this->spanId = $spanId;
        $this->parentId = $parentId;
        $this->baggageItems = $baggageItems;
    }

    public static function createAsChild(SpanContext $parentContext)
    {
        return new self(
            $parentContext->traceId,
            self::nextId(),
            $parentContext->spanId,
            $parentContext->baggageItems
        );
    }

    public static function createAsRoot(array $baggageItems = [])
    {
        $nextId = self::nextId();

        return new self(
            $nextId,
            $nextId,
            null,
            $baggageItems
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
        return bin2hex(openssl_random_pseudo_bytes(8));
    }
}
