<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/NoopSpanContext.php
 */

namespace DDTrace;

use DDTrace\Contracts\SpanContext;
use EmptyIterator;

final class NoopSpanContext implements SpanContext
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
    public function getPropagatedPrioritySampling()
    {
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function isHostRoot()
    {
        return false;
    }
}
