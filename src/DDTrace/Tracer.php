<?php

namespace DDTrace;

use DDTrace\Contracts\Scope as ScopeInterface;
use DDTrace\Contracts\Span as SpanInterface;
use DDTrace\Contracts\SpanContext as SpanContextInterface;
use DDTrace\Contracts\Tracer as TracerInterface;
use DDTrace\Encoders\MessagePack;
use DDTrace\Encoders\SpanEncoder;
use DDTrace\Exceptions\UnsupportedFormat;
use DDTrace\Log\LoggingTrait;
use DDTrace\Propagators\CurlHeadersMap;
use DDTrace\Propagators\Noop as NoopPropagator;
use DDTrace\Propagators\TextMap;
use DDTrace\Sampling\ConfigurableSampler;
use DDTrace\Sampling\Sampler;
use DDTrace\Transport\Http;
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
        $encoder = new MessagePack();
        $this->transport = $transport ?: (PHP_VERSION_ID >= 80000 ? new Internal() : new Http($encoder));
        $textMapPropagator = new TextMap($this);
        $this->propagators = $propagators ?: [
            Format::TEXT_MAP => $textMapPropagator,
            Format::HTTP_HEADERS => $textMapPropagator,
            Format::CURL_HTTP_HEADERS => new CurlHeadersMap($this),
        ];
        $this->config = array_merge($this->config, $config);
        $this->reset();
        if (PHP_VERSION_ID >= 80000) {
            foreach ($this->config['global_tags'] as $key => $val) {
                // @phpstan-ignore-next-line
                add_global_tag($key, $val);
            }
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
            // avoid rounding errors, we only care about microsecond resolution here
            $roundedStartTime = ($options->getStartTime() + 0.2) / 1000000;
            $context = SpanContext::createAsChild($reference->getContext(), $roundedStartTime);
        }

        $resource = array_key_exists('resource', $this->config) ? (string) $this->config['resource'] : null;
        $service = $this->config['service_name'];

        if (PHP_VERSION_ID >= 80000) {
            // @phpstan-ignore-next-line
            $internalSpan = active_span();
            $internalSpan->name = (string) $operationName;
            $internalSpan->service = $service;
            $internalSpan->resource = $resource;
            $internalSpan->metrics = [];
            $internalSpan->meta = [];
            // @phpstan-ignore-next-line
            $span = new Span($internalSpan, $context);
        } else {
            $span = new Span($operationName, $context, $service, $resource, $options->getStartTime());
        }

        $tags = $options->getTags() + $this->getGlobalTags();
        if ($context->getParentId() === null) {
            $tags[Tag::PID] = getmypid();
        }

        foreach ($tags as $key => $value) {
            $span->setTag($key, $value);
        }

        if ($reference === null) {
            if (\ddtrace_config_hostname_reporting_enabled()) {
                $hostname = gethostname();
                if ($hostname !== false) {
                    $span->setTag(Tag::HOSTNAME, $hostname);
                }
            }
            // Call it here so that the data is there in any case, even when shutdown fatal errors
            if ('cli' !== PHP_SAPI && \ddtrace_config_url_resource_name_enabled()) {
                $this->addUrlAsResourceNameToSpan($span);
            }
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

        $shouldFinish = $options->shouldFinishSpanOnClose() && (PHP_VERSION_ID < 80000 || $span->getParentId() != 0
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

        $this->setPrioritySampling($this->getPrioritySampling());
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

        if ('cli' !== PHP_SAPI && \ddtrace_config_url_resource_name_enabled() && $rootScope = $this->getRootScope()) {
            $this->addUrlAsResourceNameToSpan($rootScope->getSpan());
        }

        if (self::isLogDebugActive()) {
            self::logDebug('Flushing {count} traces, {spanCount} spans', [
                'count' => $this->getTracesCount(),
                'spanCount' => dd_trace_closed_spans_count(),
            ]);
        }

        // At this time, for sure we need to enforce a decision on priority sampling.
        // If the user has removed the priority sampling (e.g. due to directly assigning metrics), put it back.
        $this->setPrioritySampling($this->getPrioritySampling());
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
        if (PHP_VERSION_ID >= 80000) {
            $trace = \dd_trace_serialize_closed_spans();
            return $trace ? [$trace] : $trace;
        }

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

        $internalSpans = \dd_trace_serialize_closed_spans();

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
        if (null === $prioritySampling) {
            $prioritySampling = $this->sampler->getPrioritySampling($span);
        }
        $this->setPrioritySampling($prioritySampling);
    }

    /**
     * @param mixed $prioritySampling
     */
    public function setPrioritySampling($prioritySampling)
    {
        $this->prioritySampling = $prioritySampling;

        $rootSpan = $this->getSafeRootSpan();
        if (null === $rootSpan) {
            return;
        }

        if ($prioritySampling !== Sampling\PrioritySampling::UNKNOWN) {
            $rootSpan->setMetric('_sampling_priority_v1', $prioritySampling);
        } elseif (isset($rootSpan->metrics)) { // official API does not allow removal, so...
            unset($rootSpan->metrics['_sampling_priority_v1']);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getPrioritySampling()
    {
        // root span is source of truth for priority sampling (if it exists)
        // @phpstan-ignore-next-line 'Undefined property $metrics' in an isset() check?! Must be a phpstan bug...
        if (($rootSpan = $this->getSafeRootSpan()) && isset($rootSpan->metrics['_sampling_priority_v1'])) {
            return $rootSpan->metrics['_sampling_priority_v1'];
        }
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
}
