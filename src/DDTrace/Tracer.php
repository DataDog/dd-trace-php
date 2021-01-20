<?php

namespace DDTrace;

use DDTrace\Encoders\Json;
use DDTrace\Encoders\SpanEncoder;
use DDTrace\Encoders\MessagePack;
use DDTrace\Log\LoggingTrait;
use DDTrace\Propagators\CurlHeadersMap;
use DDTrace\Propagators\Noop as NoopPropagator;
use DDTrace\Propagators\TextMap;
use DDTrace\Sampling\ConfigurableSampler;
use DDTrace\Sampling\Sampler;
use DDTrace\Transport\Http;
use DDTrace\Transport\Noop as NoopTransport;
use DDTrace\Exceptions\UnsupportedFormat;
use DDTrace\Contracts\Scope as ScopeInterface;
use DDTrace\Contracts\Span as SpanInterface;
use DDTrace\Contracts\SpanContext as SpanContextInterface;
use DDTrace\Contracts\Tracer as TracerInterface;

final class Tracer implements TracerInterface
{
    use LoggingTrait;

    /**
     * @deprecated Use Tracer::version() instead
     *
     * Must begin with a number for Debian packaging requirements
     * Must use single-quotes for packaging script to work
     */
    const VERSION = '0.54.0';

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
        /**
         * GlobalTags holds a set of tags that will be automatically applied to
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
     * @var string
     */
    private $prioritySampling = Sampling\PrioritySampling::UNKNOWN;

    /**
     * @var string|null The user's service version, e.g. '1.2.3'
     */
    private $serviceVersion;

    /**
     * @var string|null The environment assigned to the current service.
     */
    private $environment;

    /**
     * @param Transport $transport
     * @param Propagator[] $propagators
     * @param array $config
     */
    public function __construct(Transport $transport = null, array $propagators = null, array $config = [])
    {
        $encoder = getenv('DD_TRACE_ENCODER') === 'json' ? new Json() : new MessagePack();
        $this->transport = $transport ?: new Http($encoder);
        $textMapPropagator = new TextMap($this);
        $this->propagators = $propagators ?: [
            Format::TEXT_MAP => $textMapPropagator,
            Format::HTTP_HEADERS => $textMapPropagator,
            Format::CURL_HTTP_HEADERS => new CurlHeadersMap($this),
        ];
        $this->config = array_merge($this->config, $config);
        $this->reset();
        $this->config['global_tags'] = array_merge($this->config['global_tags'], \ddtrace_config_global_tags());
        $this->serviceVersion = \ddtrace_config_service_version();
        $this->environment = \ddtrace_config_env();
    }

    public function limited()
    {
        return dd_trace_tracer_is_limited();
    }

    /**
     * Resets this tracer to its original state.
     */
    public function reset()
    {
        $this->scopeManager = new ScopeManager();
        $this->sampler = new ConfigurableSampler();
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
            array_key_exists('resource', $this->config) ? $this->config['resource'] : null,
            $options->getStartTime()
        );

        $tags = $options->getTags() + $this->getGlobalTags();
        if ($context->getParentId() === null) {
            $tags[Tag::PID] = getmypid();
        }

        foreach ($tags as $key => $value) {
            $span->setTag($key, $value);
        }

        $this->record($span);

        return $span;
    }

    private function getGlobalTags()
    {
        $tags = $this->config['global_tags'];

        // Set extra default tags from configuration
        // These take precedence over user defined global tags to encourage
        // configuring them individually

        // Application version
        if (null !== $this->serviceVersion) {
            $tags[Tag::VERSION] = $this->serviceVersion;
        }

        // Application environment
        if (null !== $this->environment) {
            $tags[Tag::ENV] = $this->environment;
        }

        return $tags;
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
        if (!array_key_exists($format, $this->propagators)) {
            throw UnsupportedFormat::forFormat($format);
        }

        $this->enforcePrioritySamplingOnRootSpan();
        $this->propagators[$format]->inject($spanContext, $carrier);
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

        // We should refactor these blocks to use a pre-flush hook
        if (\ddtrace_config_hostname_reporting_enabled()) {
            $this->addHostnameToRootSpan();
        }
        if ('cli' !== PHP_SAPI && \ddtrace_config_url_resource_name_enabled()) {
            $this->addUrlAsResourceNameToRootSpan();
        }

        if (self::isLogDebugActive()) {
            self::logDebug('Flushing {count} traces, {spanCount} spans', [
                'count' => $this->getTracesCount(),
                'spanCount' => dd_trace_closed_spans_count(),
            ]);
        }

        // At this time, for sure we need to enforce a decision on priority sampling.
        // Most probably, especially if a distributed tracing request has been done, priority sampling
        // will be already defined.
        $this->enforcePrioritySamplingOnRootSpan();
        $this->transport->send($this);
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

    /**
     * {@inheritdoc}
     */
    public function getTracesAsArray()
    {
        $tracesToBeSent = [];
        $autoFinishSpans = \ddtrace_config_autofinish_span_enabled();
        $serviceMappings = \ddtrace_config_service_mapping();

        $root = $this->getSafeRootSpan();
        if ($root) {
            $meta = \DDTrace\additional_trace_meta();
            foreach ($meta as $tag => $value) {
                $root->setTag($tag, $value, true);
            }
        }

        foreach ($this->traces as $trace) {
            $traceToBeSent = [];
            foreach ($trace as $span) {
                // If resource is empty, we normalize it the the operation name.
                if ($span->getResource() === null) {
                    $span->setResource($span->getOperationName());
                }

                if ($span->duration === null) { // is span not finished
                    if (!$autoFinishSpans) {
                        $traceToBeSent = null;
                        break;
                    }
                    $span->duration = (Time::now()) - $span->startTime; // finish span
                }
                $encodedSpan = SpanEncoder::encode($span);
                $traceToBeSent[] = $encodedSpan;
            }

            if ($traceToBeSent === null) {
                continue;
            }

            $tracesToBeSent[] = $traceToBeSent;
            if (isset($traceToBeSent[0]['trace_id'])) {
                unset($this->traces[(string) $traceToBeSent[0]['trace_id']]);
            }
        }

        $internalSpans = dd_trace_serialize_closed_spans();

        // Setting global tags on internal spans, if any
        $globalTags = $this->getGlobalTags();
        if ($globalTags) {
            foreach ($internalSpans as &$internalSpan) {
                // If resource is empty, we normalize it the the operation name.
                if (empty($internalSpan['resource'])) {
                    $internalSpan['resource'] = $internalSpan['name'];
                }
                foreach ($globalTags as $globalTagName => $globalTagValue) {
                    if (isset($internalSpan['meta'][$globalTagName])) {
                        continue;
                    }
                    $internalSpan['meta'][$globalTagName] = $globalTagValue;
                }
            }
        }

        if (!empty($internalSpans)) {
            $tracesToBeSent[0] = isset($tracesToBeSent[0])
                ? array_merge($tracesToBeSent[0], $internalSpans)
                : $internalSpans;
        }
        if (isset($tracesToBeSent[0])) {
            foreach ($tracesToBeSent[0] as &$serviceSpan) {
                // Doing service mapping here to avoid an external call. This will be refactored once
                // we completely move to internal span API.
                if (!empty($serviceSpan['service']) && !empty($serviceMappings[$serviceSpan['service']])) {
                    $serviceSpan['service'] = $serviceMappings[$serviceSpan['service']];
                }
            }
        }

        return $tracesToBeSent;
    }

    private function addHostnameToRootSpan()
    {
        $hostname = gethostname();
        if ($hostname !== false) {
            $span = $this->getRootScope()->getSpan();
            if ($span !== null) {
                $span->setTag(Tag::HOSTNAME, $hostname);
            }
        }
    }

    private function addUrlAsResourceNameToRootSpan()
    {
        $scope = $this->getRootScope();
        if (null === $scope) {
            return;
        }
        $span = $scope->getSpan();
        if (null !== $span->getResource()) {
            return;
        }

        if (!isset($_SERVER['REQUEST_METHOD'])) {
            return;
        }

        // Normalized URL as the resource name
        $resourceName = $_SERVER['REQUEST_METHOD'];
        if (isset($_SERVER['REQUEST_URI'])) {
            $resourceName .= ' ' . \DDtrace\Private_\util_uri_normalize_incoming_path($_SERVER['REQUEST_URI']);
        }
        $span->setTag(Tag::RESOURCE_NAME, $resourceName, true);
    }

    private function record(Span $span)
    {
        if (!array_key_exists($span->context->traceId, $this->traces)) {
            $this->traces[$span->context->traceId] = [];
        }
        $this->traces[$span->context->traceId][$span->context->spanId] = $span;
        if (\ddtrace_config_debug_enabled()) {
            self::logDebug('New span {operation} {resource} recorded.', [
                'operation' => $span->operationName,
                'resource' => $span->resource,
            ]);
        }
    }

    /**
     * Handles the priority sampling for the current span.
     *
     * @param Span $span
     */
    private function setPrioritySamplingFromSpan(Span $span)
    {
        if (!\ddtrace_config_priority_sampling_enabled()) {
            return;
        }

        if (!$span->getContext()->isHostRoot()) {
            // Only root spans for each host must have the sampling priority value set.
            return;
        }

        $this->prioritySampling = $span->getContext()->getPropagatedPrioritySampling();
        if (null === $this->prioritySampling) {
            $this->prioritySampling = $this->sampler->getPrioritySampling($span);
        }
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

    /**
     * Returns the root span or null and never throws an exception.
     *
     * @return SpanInterface|null
     */
    public function getSafeRootSpan()
    {
        $rootScope = $this->getRootScope();

        if (empty($rootScope)) {
            return null;
        }

        return $rootScope->getSpan();
    }

    /**
     * @return string
     */
    public static function version()
    {
        return self::VERSION;
    }

    /**
     * {@inheritdoc}
     */
    public function getTracesCount()
    {
        return count($this->traces);
    }

    /**
     * Enforce priority sampling on the root span.
     */
    private function enforcePrioritySamplingOnRootSpan()
    {
        if ($this->prioritySampling !== Sampling\PrioritySampling::UNKNOWN) {
            return;
        }

        $rootScope = $this->getRootScope();
        if (null === $rootScope) {
            return;
        }
        $rootSpan = $rootScope->getSpan();
        if (null === $rootSpan) {
            return;
        }

        $this->setPrioritySamplingFromSpan($rootSpan);
    }
}
