<?php

namespace DDTrace;

use DDTrace\Encoders\Json;
use DDTrace\Propagators\CurlHeadersMap;
use DDTrace\Propagators\Noop as NoopPropagator;
use DDTrace\Propagators\TextMap;
use DDTrace\Sampling\AlwaysKeepSampler;
use DDTrace\Sampling\Sampler;
use DDTrace\Transport\Http;
use DDTrace\Transport\Noop as NoopTransport;
use DDTrace\Exceptions\UnsupportedFormat;
use DDTrace\Contracts\Scope as ScopeInterface;
use DDTrace\Contracts\SpanContext as SpanContextInterface;
use DDTrace\Contracts\Tracer as TracerInterface;

final class Tracer implements TracerInterface
{
    const VERSION = '0.10.0-beta';

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
     * @var Sampler
     */
    private $sampler;

    /**
     * @var Propagator[]
     */
    private $propagators;

    /**
     * @var array
     */
    private $config = [
        /**
         * ServiceName specifies the name of this application.
         */
        'service_name' => PHP_SAPI,
        /**
         * Enabled, when false, returns a no-op implementation of the Tracer.
         */
        'enabled' => true,
        /** GlobalTags holds a set of tags that will be automatically applied to
         * all spans.
         */
        'global_tags' => [],
    ];

    /**
     * @var ScopeManager
     */
    private $scopeManager;

    /**
     * @var ScopeInterface|null
     */
    private $rootScope;

    /**
     * @var Configuration
     */
    private $globalConfig;

    private $prioritySampling;

    /**
     * @param Transport $transport
     * @param Propagator[] $propagators
     * @param array $config
     */
    public function __construct(Transport $transport = null, array $propagators = null, array $config = [])
    {
        $this->transport = $transport ?: new Http(new Json());
        $textMapPropagator = new TextMap($this);
        $this->propagators = $propagators ?: [
            Format::TEXT_MAP => $textMapPropagator,
            Format::HTTP_HEADERS => $textMapPropagator,
            Format::CURL_HTTP_HEADERS => new CurlHeadersMap($this),
        ];
        $this->config = array_merge($this->config, $config);
        $this->reset();
    }

    /**
     * Resets this tracer to its original state.
     */
    public function reset()
    {
        $this->scopeManager = new ScopeManager();
        $this->globalConfig = Configuration::get();
        $this->sampler = new AlwaysKeepSampler();
        $this->traces = [];
    }

    /**
     * @return Tracer
     */
    public static function noop()
    {
        return new self(
            new NoopTransport(),
            [
                Format::BINARY => new NoopPropagator(),
                Format::TEXT_MAP => new NoopPropagator(),
                Format::HTTP_HEADERS => new NoopPropagator(),
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

        if (!($options instanceof StartSpanOptions)) {
            $options = StartSpanOptions::create($options);
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

        $this->handlePrioritySampling($span);

        $tags = $options->getTags() + $this->config['global_tags'];
        if ($reference === null) {
            $tags[Tag::PID] = getmypid();
        }

        foreach ($tags as $key => $value) {
            $span->setTag($key, $value);
        }

        $this->record($span);

        return $span;
    }

    /**
     * {@inheritdoc}
     */
    public function startRootSpan($operationName, $options = [])
    {
        return $this->rootScope = $this->startActiveSpan($operationName, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function getRootScope()
    {
        return $this->rootScope;
    }

    /**
     * {@inheritdoc}
     */
    public function startActiveSpan($operationName, $options = [])
    {
        if (!($options instanceof StartSpanOptions)) {
            $options = StartSpanOptions::create($options);
        }

        $parentService = null;

        if (($activeSpan = $this->getActiveSpan()) !== null) {
            $options = $options->withParent($activeSpan);
            $tags = $options->getTags();
            if (!array_key_exists(Tag::SERVICE_NAME, $tags)) {
                $parentService = $activeSpan->getService();
            }
        }

        $span = $this->startSpan($operationName, $options);
        if ($parentService !== null) {
            $span->setTag(Tag::SERVICE_NAME, $parentService);
        }

        return $this->scopeManager->activate($span, $options->shouldFinishSpanOnClose());
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
    public function inject(SpanContextInterface $spanContext, $format, &$carrier)
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

        $tracesToBeSent = $this->shiftFinishedTraces();

        if (empty($tracesToBeSent)) {
            return;
        }

        $numberOfTraces = 0;
        foreach ($tracesToBeSent as $trace) {
            foreach ($trace as $span) {
                $numberOfTraces = $numberOfTraces + 1;
            }
        }

        $this->transport->send($tracesToBeSent);
    }

    /**
     * {@inheritdoc}
     */
    public function getScopeManager()
    {
        return $this->scopeManager;
    }

    /**
     * @return null|Span
     */
    public function getActiveSpan()
    {
        if (null !== ($activeScope = $this->scopeManager->getActive())) {
            return $activeScope->getSpan();
        }

        return null;
    }

    private function shiftFinishedTraces()
    {
        $tracesToBeSent = [];

        $autoFinishSpans = $this->globalConfig->isAutofinishSpansEnabled();

        foreach ($this->traces as $trace) {
            $traceToBeSent = [];

            foreach ($trace as $span) {
                if (!$span->isFinished()) {
                    if (!$autoFinishSpans) {
                        $traceToBeSent = null;
                        break;
                    }
                    $span->finish();
                }
                $traceToBeSent[] = $span;
            }

            if ($traceToBeSent === null) {
                continue;
            }

            $tracesToBeSent[] = $traceToBeSent;
            unset($this->traces[$traceToBeSent[0]->getTraceId()]);
        }

        return $tracesToBeSent;
    }

    private function record(Span $span)
    {
        if (!array_key_exists($span->getTraceId(), $this->traces)) {
            $this->traces[$span->getTraceId()] = [];
        }

        $this->traces[$span->getTraceId()][$span->getSpanId()] = $span;
    }

    /**
     * Handles the priority sampling for the current span.
     *
     * @param Span $span
     */
    private function handlePrioritySampling(Span $span)
    {
        if (!$this->globalConfig->isPrioritySamplingEnabled()) {
            return;
        }

        if (!$span->getContext()->isHostRoot()) {
            // Only root spans for each host must have the sampling priority value set.
            return;
        }

        $this->prioritySampling = $span->getContext()->getPropagatedPrioritySampling()
            ?: $this->sampler->getPrioritySampling($span);
    }

    /**
     * @param mixed $prioritySampling
     */
    public function setPrioritySampling($prioritySampling)
    {
        $this->prioritySampling = $prioritySampling;
    }

    /**
     * {@inheritdoc}
     */
    public function getPrioritySampling()
    {
        return $this->prioritySampling;
    }
}
