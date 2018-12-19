<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/NoopSpan.php
 */

namespace DDTrace\OpenTracing;

use DDTrace\Contracts\Span;

final class NoopSpan implements Span
{
    public static function create()
    {
        return new self();
    }

    /**
     * {@inheritdoc}
     */
    public function getOperationName()
    {
        return 'noop_span';
    }

    /**
     * {@inheritdoc}
     */
    public function getContext()
    {
        return NoopSpanContext::create();
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
    public function overwriteOperationName($newOperationName)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function setTag($key, $value)
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
        return null;
    }
}
