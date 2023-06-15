<?php

namespace DDTrace;

use DDTrace\Contracts\Span as SpanInterface;
use DDTrace\Contracts\SpanContext as SpanContextInterface;
use DDTrace\Contracts\Tracer as TracerInterface;
use DDTrace\Exceptions\UnsupportedFormat;
use DDTrace\Log\LoggingTrait;
use DDTrace\Propagators\Noop as NoopPropagator;
use DDTrace\Propagators\TextMap;
use DDTrace\Transport\Internal;
use DDTrace\Transport\Noop;
use DDTrace\Transport\Noop as NoopTransport;
use DDTrace\Util\ObjectKVStore;

final class Tracer implements TracerInterface
{
    use LoggingTrait;

    /**
     * @deprecated Use Tracer::version() instead
     *
     * Must begin with a number for Debian packaging requirements
     * Must use single-quotes for packaging script to work
     */
    const VERSION = '0.88.0';

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
     * @var string The user's service version, e.g. '1.2.3'
     */
    private $serviceVersion;

    /**
     * @var string The environment assigned to the current service.
     */
    private $environment;

    /**
     * @var SpanContext|null Contains a possible distributed tracing context
     */
    private $rootContext;

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
        foreach ($this->config['global_tags'] as $key => $val) {
            add_global_tag($key, $val);
        }
        $this->serviceVersion = \ddtrace_config_service_version();
        $this->environment = \ddtrace_config_env();

        $context = current_context();
        if (isset($context["distributed_tracing_parent_id"])) {
            $parentId = $context["distributed_tracing_parent_id"];
            $this->rootContext = new SpanContext($context["trace_id"], $parentId, null, [], true);
            if (isset($context["distributed_tracing_origin"])) {
                $this->rootContext->origin = $context["distributed_tracing_origin"];
            }
        }

        $this->scopeManager = new ScopeManager($this->rootContext);
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
        if (_ddtrace_config_bool(ini_get("datadog.trace.enabled"), false)) {
            // Do a full shutdown and re-startup of the tracer, which implies clearing the internal span stack
            ini_set("datadog.trace.enabled", 0);
            ini_set("datadog.trace.enabled", 1);
        }
        $this->scopeManager = new ScopeManager($this->rootContext);
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
        if (!$this->config['enabled'] || !\ddtrace_config_trace_enabled()) {
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

        // This dynamic property ensures that the scope manager does not recognize startSpan() spans without activation
        ObjectKVStore::put($internalSpan, 'ddtrace_scope_activated', false);

        return $span;
    }

    /**
     * {@inheritdoc}
     */
    public function startRootSpan($operationName, $options = [])
    {
        $rootScope = $this->startActiveSpan($operationName, $options);
        $this->setPrioritySamplingFromSpan($rootScope->getSpan()); // make it the source of truth
        return $rootScope;
    }

    /**
     * {@inheritdoc}
     */
    public function getRootScope()
    {
        return $this->scopeManager->getPrimaryRoot();
    }

    /**
     * {@inheritdoc}
     */
    public function startActiveSpan($operationName, $options = [])
    {
        if (!($options instanceof StartSpanOptions)) {
            $options = StartSpanOptions::create($options);
        }

        if (!root_span() && !$options->getReferences() && $this->rootContext) {
            $options = $options->withParent($this->rootContext);
        }

        $parentService = null;
        if (($activeScope = $this->scopeManager->getTopScope()) !== null) {
            $activeSpan = $activeScope->getSpan();
            $tags = $options->getTags();
            if (!array_key_exists(Tag::SERVICE_NAME, $tags)) {
                $parentService = $activeSpan->getService();
            }
            $options = $options->withParent($activeSpan);
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
            $resourceName .= ' ' . \DDTrace\Util\Normalizer::uriNormalizeIncomingPath($_SERVER['REQUEST_URI']);
        }
        $span->setTag(Tag::RESOURCE_NAME, $resourceName, true);
    }

    /**
     * Handles the priority sampling for the current span.
     *
     * @param SpanInterface $span
     */
    private function setPrioritySamplingFromSpan(SpanInterface $span)
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
