<?php

namespace DDTrace;

use DDTrace\Propagators\Noop as NoopPropagator;
use DDTrace\Transport\Noop as NoopTransport;
use OpenTracing\Exceptions\UnsupportedFormat;
use OpenTracing\NoopSpan;
use OpenTracing\Reference;
use OpenTracing\SpanContext as OpenTracingContext;
use OpenTracing\SpanOptions;
use OpenTracing\Tracer as OpenTracingTracer;
use OpenTracing\Formats;

final class Tracer implements OpenTracingTracer
{
    /**
     * @var Span[][]
     */
    private $traces = [];

    /**
     * The transport mechanism used to delivery spans to the agent
     *
     * @var Transport
     */
    private $transport;

    /**
     * @var Propagator[]
     */
    private $propagators;

    /**
     * @var array
     */
    private $config = [
        /**
         * Enabled, when false, returns a no-op implementation of the Tracer.
         */
        'enabled' => true,
        /**
         * Debug, when true, writes details to logs.
         */
        'debug' => false,
        /**
         * ServiceName specifies the name of this application.
         */
        'service_name' => PHP_SAPI,
        /** GlobalTags holds a set of tags that will be automatically applied to
         * all spans.
         */
        'global_tags' => [],
    ];

    /**
     * Tracer constructor.
     * @param Transport $transport
     * @param Propagator[] $propagators
     * @param array $config
     */
    public function __construct(Transport $transport, array $propagators = [], array $config = [])
    {
        $this->transport = $transport;
        $this->propagators = $propagators;
        $this->config = array_merge($this->config, $config);
    }

    public static function noop()
    {
        return new self(
            new NoopTransport,
            [
                Formats\BINARY => new NoopPropagator,
                Formats\TEXT_MAP => new NoopPropagator,
                Formats\HTTP_HEADERS => new NoopPropagator,
            ],
            ['enabled' => false]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function startSpan($operationName, $options = [])
    {
        if (!$this->config['enabled']) {
            return NoopSpan::create();
        }

        if (!($options instanceof SpanOptions)) {
            $options = SpanOptions::create($options);
        }

        $reference = $this->findParent($options->getReferences());

        if ($reference === null) {
            $context = SpanContext::createAsRoot();
        } else {
            $context = SpanContext::createAsChild($reference->getContext());
        }

        $span = new Span(
            $operationName,
            $context,
            $this->config['service_name'],
            array_key_exists('resource', $this->config) ? $this->config['resource'] : $operationName,
            $options->getStartTime()
        );

        $span->setTags($options->getTags() + $this->config['global_tags']);

        $this->record($span);

        return $span;
    }

    /**
     * @param array|Reference[] $references
     * @return null|Reference
     */
    private function findParent(array $references)
    {
        foreach ($references as $reference) {
            if ($reference->isType(Reference::CHILD_OF)) {
                return $reference;
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function inject(OpenTracingContext $spanContext, $format, &$carrier)
    {
        if (array_key_exists($format, $this->propagators)) {
            $this->propagators[$format]->inject($spanContext, $carrier);
            return;
        }

        throw UnsupportedFormat::forFormat($format);
    }

    /**
     * {@inheritdoc}
     */
    public function extract($format, $carrier)
    {
        if (array_key_exists($format, $this->propagators)) {
            return $this->propagators[$format]->extract($carrier);
        }

        throw UnsupportedFormat::forFormat($format);
    }

    /**
     * @return void
     */
    public function flush()
    {
        if (!$this->config['enabled']) {
            return;
        }

        $tracesToBeSent = [];

        foreach ($this->traces as $trace) {
            $traceToBeSent = [];

            foreach ($trace as $span) {
                if (!$span->isFinished()) {
                    $traceToBeSent = null;
                    break;
                }
                $tracesToBeSent[] = $span;
            }

            if ($traceToBeSent === null) {
                continue;
            }

            $tracesToBeSent[] = $traceToBeSent;
            unset($this->traces[$span->getTraceId()]);
        }

        $this->transport->send($tracesToBeSent);
    }

    private function record(Span $span)
    {
        if (!array_key_exists($span->getTraceId(), $this->traces)) {
            $this->traces[$span->getTraceId()] = [];
        }

        $this->traces[$span->getTraceId()][$span->getSpanId()] = $span;
    }
}
