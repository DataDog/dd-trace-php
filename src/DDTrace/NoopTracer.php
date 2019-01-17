<?php

/**
 * Ported from opentracing/opentracing
 * @see https://github.com/opentracing/opentracing-php/blob/master/src/OpenTracing/NoopTracer.php
 */

namespace DDTrace;

use DDTrace\Contracts\SpanContext as SpanContextInterface;
use DDTrace\Contracts\Tracer as TracerInterface;

final class NoopTracer implements TracerInterface
{
    /**
     * {@inheritdoc}
     */
    public function getActiveSpan()
    {
        return NoopSpan::create();
    }

    /**
     * {@inheritdoc}
     */
    public function getScopeManager()
    {
        return new NoopScopeManager();
    }

    public static function create()
    {
        return new self();
    }

    /**
     * {@inheritdoc}
     */
    public function startSpan($operationName, $options = [])
    {
        return NoopSpan::create();
    }

    /**
     * {@inheritdoc}
     */
    public function startActiveSpan($operationName, $finishSpanOnClose = true, $options = [])
    {
        return NoopScope::create();
    }

    /**
     * {@inheritdoc}
     */
    public function inject(SpanContextInterface $spanContext, $format, &$carrier)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function extract($format, $carrier)
    {
        return NoopSpanContext::create();
    }

    /**
     * {@inheritdoc}
     */
    public function flush()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getPrioritySampling()
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function startRootSpan($operationName, $options = [])
    {
        return NoopScope::create();
    }

    /**
     * {@inheritdoc}
     */
    public function getRootScope()
    {
        return NoopScope::create();
    }
}
