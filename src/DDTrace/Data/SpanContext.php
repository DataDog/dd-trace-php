<?php

namespace DDTrace\Data;

class SpanContext
{
    /**
     * The unique integer (64-bit unsigned) ID of the trace containing this span.
     * It is stored in hexadecimal representation.
     *
     * @var string
     */
    public $traceId;

    /**
     * The span integer (64-bit unsigned) ID.
     * It is stored in hexadecimal representation.
     *
     * @var string
     */
    public $spanId;

    /**
     * The span integer ID of the parent span.
     * It is stored in hexadecimal representation.
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
     * @var int
     */
    public $propagatedPrioritySampling;

    /**
     * @var SpanContextInterface
     */
    public $parentContext;

    /**
     * @var array
     */
    public $baggageItems;

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
}
