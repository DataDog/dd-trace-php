<?php

namespace DDTrace\OpenTracer;

use DDTrace\Contracts\Tracer as TracerInterface;
use DDTrace\Tracer as DDTracer;
use DDTrace\Transport;
use OpenTracing\ScopeManager as OTScopeManager;
use OpenTracing\SpanContext as OTSpanContext;
use OpenTracing\Tracer as OTTracer;

final class Tracer implements OTTracer
{
    /**
     * @var TracerInterface
     */
    private $tracer;

    /**
     * @var OTScopeManager
     */
    private $scopeManager;

    /**
     * @param TracerInterface|null $tracer
     */
    public function __construct(TracerInterface $tracer = null)
    {
        $this->tracer = $tracer ?: self::make();
    }

    public static function make(Transport $transport = null, array $propagators = null, array $config = [])
    {
        return new self(
            new DDTracer($transport, $propagators, $config)
        );
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
    public function inject(OTSpanContext $spanContext, $format, &$carrier)
    {
        $this->tracer->inject(
            SpanContext::toDDSpanContext($spanContext),
            $format,
            $carrier
        );
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
}
