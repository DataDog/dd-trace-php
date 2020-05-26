<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/SpanContext.php
 */

namespace DDTrace\Contracts;

use IteratorAggregate;

/**
 * SpanContext must be immutable in order to avoid complicated lifetime
 * issues around Span finish and references.
 *
 * Baggage items are key => value string pairs that apply to the given Span,
 * its SpanContext, and all Spans which directly or transitively reference
 * the local Span. That is, baggage items propagate in-band along with the
 * trace itself.
 */
interface SpanContext extends IteratorAggregate
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
     * @return int
     */
    public function getPropagatedPrioritySampling();

    /**
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
