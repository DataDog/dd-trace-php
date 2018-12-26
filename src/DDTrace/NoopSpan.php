<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/NoopSpan.php
 */

namespace DDTrace;

use DDTrace\Contracts\Span as SpanInterface;

final class NoopSpan implements SpanInterface
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
    public function getTag($key)
    {
        return null;
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
    public function setError($error)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function setRawError($message, $type)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function hasError()
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getStartTime()
    {
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getDuration()
    {
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function getTraceId()
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getSpanId()
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getParentId()
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getResource()
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getService()
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getType()
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function isFinished()
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getAllTags()
    {
        return [];
    }
}
