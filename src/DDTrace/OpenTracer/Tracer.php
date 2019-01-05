<?php

namespace DDTrace\OpenTracer;

use DDTrace\Contracts\SpanContext as SpanContextInterface;
use DDTrace\Contracts\Tracer as TracerInterface;
use OpenTracing\Tracer as OpenTracingTracer;

final class Tracer implements TracerInterface
{
    /**
     * @var OpenTracingTracer
     */
    private $tracer;

    /**
     * @var ScopeManager
     */
    private $scopeManager;

    /**
     * @param OpenTracingTracer $tracer
     */
    public function __construct(OpenTracingTracer $tracer)
    {
        $this->tracer = $tracer;
    }

    /**
     * {@inheritdoc}
     */
    public function startSpan($operationName, $options = [])
    {
        return new Span(
            $this->tracer->startSpan($operationName, $options)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function startActiveSpan($operationName, $options = [])
    {
        return new Scope(
            $this->tracer->startActiveSpan($operationName, $options)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function inject(SpanContextInterface $spanContext, $format, &$carrier)
    {
        // @TODO Wrap or implement this
        $this->tracer->inject($spanContext, $format, $carrier);
    }

    /**
     * {@inheritdoc}
     */
    public function extract($format, $carrier)
    {
        $this->tracer->extract($format, $carrier);
    }

    /**
     * {@inheritdoc}
     */
    public function flush()
    {
        $this->tracer->flush();
    }

    /**
     * {@inheritdoc}
     */
    public function getScopeManager()
    {
        if (isset($this->scopeManager)) {
            return $this->scopeManager;
        }
        return $this->scopeManager = new ScopeManager(
            $this->tracer->getScopeManager()
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getActiveSpan()
    {
        return $this->tracer->getActiveSpan();
    }

    /**
     * @return mixed
     */
    public function getPrioritySampling()
    {
        // @TODO Add support for priority sampling
        return null;
    }
}
