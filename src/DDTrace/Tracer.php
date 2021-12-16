<?php

namespace DDTrace;

use DDTrace\Contracts\Scope as ScopeInterface;
use DDTrace\Contracts\Span as SpanInterface;
use DDTrace\Contracts\SpanContext as SpanContextInterface;
use DDTrace\Contracts\Tracer as TracerInterface;
use DDTrace\Exceptions\UnsupportedFormat;
use DDTrace\Log\LoggingTrait;
use DDTrace\Propagators\CurlHeadersMap;
use DDTrace\Propagators\Noop as NoopPropagator;
use DDTrace\Propagators\TextMap;
use DDTrace\Transport\Internal;
use DDTrace\Transport\Noop;
use DDTrace\Transport\Noop as NoopTransport;

final class Tracer implements TracerInterface
{
    use LoggingTrait;

    /**
     * @deprecated Use Tracer::version() instead
     *
     * Must begin with a number for Debian packaging requirements
     * Must use single-quotes for packaging script to work
     */
    const VERSION = '1.0.0-nightly';

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
     * @var string The user's service version, e.g. '1.2.3'
     */
    private $serviceVersion;

    /**
     * @var string The environment assigned to the current service.
     */
    private $environment;

    /**
     * @param Transport $transport
     * @param Propagator[] $propagators
     * @param array $config
     */
    public function __construct(Transport $transport = null, array $propagators = null, array $config = [])
    {
        $this->transport = $transport ?: new Internal();
        $textMapPropagator = new TextMap($this);
        $this->propagators = $propagators ?: [
            Format::TEXT_MAP => $textMapPropagator,
            Format::HTTP_HEADERS => $textMapPropagator,
        ];
        $this->config = array_merge($this->config, $config);
        $this->reset();
        foreach ($this->config['global_tags'] as $key => $val) {
            add_global_tag($key, $val);
        }
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

        // avoid rounding errors, we only care about microsecond resolution here
        // a value of 0 defaults to current time
        $roundedStartTime = $options->getStartTime() ? ($options->getStartTime() + 0.2) / 1000000 : 0;
        if ($reference === null) {
            $context = SpanContext::createAsRoot([], $roundedStartTime);
        } else {
            $context = SpanContext::createAsChild($reference->getContext(), $roundedStartTime);
        }

        $resource = array_key_exists('resource', $this->config) ? (string) $this->config['resource'] : null;
        $service = $this->config['service_name'];

        $internalSpan = active_span();
        $internalSpan->name = (string) $operationName;
        $internalSpan->service = $service;
        $internalSpan->resource = $resource;
        if (!isset($internalSpan->metrics)) {
            $internalSpan->metrics = [];
        }
        if (!isset($internalSpan->meta)) {
            $internalSpan->meta = [];
        }
        $span = new Span($internalSpan, $context);

        foreach ($options->getTags() as $key => $val) {
            $span->setTag($key, $val);
        }

        // Call it here so that the data is there in any case, even when shutdown fatal errors
        if (
            ($reference === null || $reference->getContext()->isDistributedTracingActivationContext())
            && 'cli' !== PHP_SAPI && \ddtrace_config_url_resource_name_enabled()
        ) {
            $this->addUrlAsResourceNameToSpan($span);
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
        if ("" !== $this->serviceVersion) {
            $tags[Tag::VERSION] = $this->serviceVersion;
        }

        // Application environment
        if ("" !== $this->environment) {
            $tags[Tag::ENV] = $this->environment;
        }

        return $tags;
    }

    /**
     * {@inheritdoc}
     */
    public function startRootSpan($operationName, $options = [])
    {
        $this->rootScope = $this->startActiveSpan($operationName, $options);
        $this->setPrioritySamplingFromSpan($this->rootScope->getSpan()); // make it the source of truth
        return $this->rootScope;
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
            $tags = $options->getTags();
            if (!array_key_exists(Tag::SERVICE_NAME, $tags)) {
                $parentService = $activeSpan->getService();
            }
        }
        if (!$parent = $activeSpan) {
            // Handle the case where the trace root was created outside of userland control
            if (!\dd_trace_env_config('DD_TRACE_GENERATE_ROOT_SPAN') && active_span()) {
                $trace_id = trace_id();
                $parent = new SpanContext($trace_id, $trace_id);
            }
        }
        if ($parent) {
            $options = $options->withParent($parent);
        }

        $span = $this->startSpan($operationName, $options);
        if ($parentService !== null) {
            $span->setTag(Tag::SERVICE_NAME, $parentService);
        }

        $shouldFinish = $options->shouldFinishSpanOnClose() && ($span->getParentId() != 0
                || !\dd_trace_env_config('DD_TRACE_GENERATE_ROOT_SPAN'));
        return $this->scopeManager->activate($span, $shouldFinish);
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

        if ('cli' !== PHP_SAPI && \ddtrace_config_url_resource_name_enabled() && $rootSpan = $this->getSafeRootSpan()) {
            $this->addUrlAsResourceNameToSpan($rootSpan);
        }

        if (self::isLogDebugActive()) {
            self::logDebug('Flushing {count} traces, {spanCount} spans', [
                'count' => $this->getTracesCount(),
                'spanCount' => dd_trace_closed_spans_count(),
            ]);
        }

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
        $trace = \dd_trace_serialize_closed_spans();
        return $trace ? [$trace] : $trace;
    }

    public function addUrlAsResourceNameToSpan(Contracts\Span $span)
    {
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

        $prioritySampling = $span->getContext()->getPropagatedPrioritySampling();
        if (null !== $prioritySampling) {
            $this->setPrioritySampling($prioritySampling);
        }
    }

    /**
     * @param mixed $prioritySampling
     */
    public function setPrioritySampling($prioritySampling)
    {
        set_priority_sampling($prioritySampling);
        set_priority_sampling($prioritySampling, true);
    }

    /**
     * {@inheritdoc}
     */
    public function getPrioritySampling()
    {
        return get_priority_sampling();
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
            if ($internalRootSpan = root_span()) {
                // This will not set the distributed tracing activation context properly: do with internal migration
                $traceId = trace_id();
                return new Span($internalRootSpan, new SpanContext($traceId, $traceId));
            }
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
}
