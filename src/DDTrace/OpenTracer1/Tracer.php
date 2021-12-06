<?php

namespace DDTrace\OpenTracer1;

use DDTrace\Contracts\Tracer as TracerInterface;
use DDTrace\GlobalTracer;
use DDTrace\Propagator;
use DDTrace\Tracer as DDTracer;
use DDTrace\Transport;
use OpenTracing\Scope as OTScope;
use OpenTracing\ScopeManager as OTScopeManager;
use OpenTracing\Span as OTSpan;
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
        $this->tracer = $tracer ?: GlobalTracer::get();
    }

    /**
     * @param Transport $transport
     * @param Propagator[] $propagators
     * @param array $config
     * @return self
     */
    public static function make(Transport $transport = null, array $propagators = null, array $config = [])
    {
        return new self(
            new DDTracer($transport, $propagators, $config)
        );
    }

    private function deconstructStartSpanOptions(\OpenTracing\StartSpanOptions $obj)
    {
        $options = [];

        $tags = $obj->getTags();
        if ($tags) {
            $options['tags'] = $tags;
        }

        $start_time = $obj->getStartTime();
        if (isset($start_time)) {
            $options['start_time'] = $start_time;
        }

        $options['finish_span_on_close'] = $obj->shouldFinishSpanOnClose();
        $options['ignore_active_span'] = $obj->shouldIgnoreActiveSpan();

        /* Later: finish supporting OpenTracing\References
        $references = $obj->getReferences();
        if (!empty($references)) {
            $options['references'] = $references;
        }
        */

        return $options;
    }

    /**
     * {@inheritdoc}
     */
    public function startSpan(string $operationName, $options = []): OTSpan
    {
        if ($options instanceof \OpenTracing\StartSpanOptions) {
            $options = self::deconstructStartSpanOptions($options);
        }
        return new Span(
            $this->tracer->startSpan($operationName, $options)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function startActiveSpan(string $operationName, $options = []): OTScope
    {
        if ($options instanceof \OpenTracing\StartSpanOptions) {
            $options = self::deconstructStartSpanOptions($options);
        }
        return new Scope(
            $this->tracer->startActiveSpan($operationName, $options)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function inject(OTSpanContext $spanContext, string $format, &$carrier): void
    {
        $this->tracer->inject(
            $spanContext instanceof SpanContext
                ? $spanContext->unwrapped()
                : SpanContext::toDDSpanContext($spanContext),
            $format,
            $carrier
        );
    }

    /**
     * {@inheritdoc}
     */
    public function extract(string $format, $carrier): ?OTSpanContext
    {
        return new SpanContext(
            $this->tracer->extract($format, $carrier)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function flush(): void
    {
        $this->tracer->flush();
    }

    /**
     * {@inheritdoc}
     */
    public function getScopeManager(): OTScopeManager
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
    public function getActiveSpan(): ?OTSpan
    {
        $activeSpan = $this->tracer->getActiveSpan();
        if (null === $activeSpan) {
            return null;
        }
        return new Span($activeSpan);
    }

    /**
     * @return TracerInterface
     */
    public function unwrapped()
    {
        return $this->tracer;
    }
}
