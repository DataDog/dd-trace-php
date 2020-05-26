<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/NoopSpanContext.php
 */

namespace DDTrace;

use DDTrace\Contracts\SpanContext as SpanContextInterface;
use EmptyIterator;

final class NoopSpanContext implements SpanContextInterface
{
    public static function create()
    {
        return new self();
    }

    /**
     * {@inheritdoc}
     */
    public function getIterator()
    {
        return new EmptyIterator();
    }

    /**
     * {@inheritdoc}
     */
    public function getBaggageItem($key)
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function withBaggageItem($key, $value)
    {
        return new self();
    }

    /**
     * {@inheritdoc}
     */
    public function getAllBaggageItems()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getPropagatedPrioritySampling()
    {
        return 0;
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
    public function isHostRoot()
    {
        return false;
    }

    /**
     * @return string
     */
    public function getTraceId()
    {
        return '';
    }

    /**
     * @return string
     */
    public function getSpanId()
    {
        return '';
    }

    /**
     * @return string|null
     */
    public function getParentId()
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function isDistributedTracingActivationContext()
    {
        return false;
    }
}
