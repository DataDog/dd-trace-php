<?php

namespace DDTrace\Integrations\Guzzle;

use DDTrace\Configuration;
use DDTrace\Contracts\Span;
use DDTrace\Format;
use DDTrace\GlobalTracer;
use DDTrace\Integrations\Integration;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\CodeTracer;

/**
 * Abstract integration loader for Guzzle integrations.
 */
abstract class AbstractGuzzleIntegrationLoader
{
    /**
     * @var CodeTracer
     */
    private $codeTracer;

    /**
     * @param Span $span
     * @param mixed $request
     */
    protected abstract function setUrlTag(Span $span, $request);

    /**
     * @param Span $span
     * @param mixed $response
     */
    protected abstract function setStatusCodeTag(Span $span, $response);

    /**
     * @param mixed $request
     */
    protected abstract function extractRequestHeaders($request);

    /**
     * @param mixed $request
     * @param array $headers
     */
    protected abstract function addRequestHeaders($request, $headers);

    /**
     * @return string
     */
    protected abstract function getMethodToTrace();

    /**
     * @param CodeTracer $codeTracer
     */
    public function __construct(CodeTracer $codeTracer)
    {
        $this->codeTracer = $codeTracer;
    }

    /**
     * @param string $name
     * @return int
     */
    public function load($name)
    {
        $self = $this;
        $method = $this->getMethodToTrace();

        $this->codeTracer->tracePublicMethod(
            'GuzzleHttp\Client',
            $method,
            function (Span $span, array $args) use ($self, $name, $method) {
                list($request) = $args;
                $self->applyDistributedTracingHeaders($span, $request);
                $span->setTag(Tag::SPAN_TYPE, Type::HTTP_CLIENT);
                $span->setTag(Tag::SERVICE_NAME, $name);
                $span->setTag(Tag::HTTP_METHOD, $request->getMethod());
                $self->setUrlTag($span, $request);
                $span->setTag(Tag::RESOURCE_NAME, $method);
            },
            function (Span $span, $response) use ($self) {
                $self->setStatusCodeTag($span, $response);
            }
        );

        return Integration::LOADED;
    }

    /**
     * @param mixed $request
     * @param Span $span
     */
    public function applyDistributedTracingHeaders(Span $span, $request)
    {
        if (!Configuration::get()->isDistributedTracingEnabled()) {
            return;
        }

        $headers = $this->extractRequestHeaders($request);

        $context = $span->getContext();
        $tracer = GlobalTracer::get();
        $tracer->inject($context, Format::HTTP_HEADERS, $headers);
        $this->addRequestHeaders($request, $headers);
    }
}
