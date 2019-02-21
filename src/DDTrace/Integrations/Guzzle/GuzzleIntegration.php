<?php


namespace DDTrace\Integrations\Guzzle;

use DDTrace\Configuration;
use DDTrace\Contracts\Span;
use DDTrace\Format;
use DDTrace\GlobalTracer;
use DDTrace\Http\Urls;
use DDTrace\Integrations\Integration;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\CodeTracer;

final class GuzzleIntegration
{
    const NAME = 'guzzle';

    /**
     * @var CodeTracer
     */
    private $codeTracer;

    public static function load()
    {
        $instance = new self();
        $instance->doLoad();
        return Integration::LOADED;
    }

    /**
     * @return int
     */
    public function doLoad()
    {
        $self = $this;

        $postCallback = function (Span $span, $response) use ($self) {
            $self->setStatusCodeTag($span, $response);
        };

        // Guzzle 5
        $this->codeTracer->tracePublicMethod(
            'GuzzleHttp\Client',
            'send',
            $this->buildPreCallback('send'),
            $postCallback
        );
        // Guzzle 6
        $this->codeTracer->tracePublicMethod(
            'GuzzleHttp\Client',
            'transfer',
            $this->buildPreCallback('transfer'),
            $postCallback
        );

        return Integration::LOADED;
    }

    /**
     * @param string $method
     * @return \Closure
     */
    private function buildPreCallback($method)
    {
        $self = $this;
        return function (Span $span, array $args) use ($self, $method) {
            list($request) = $args;
            $self->applyDistributedTracingHeaders($span, $request);
            $span->setTag(Tag::SPAN_TYPE, Type::HTTP_CLIENT);
            $span->setTag(Tag::SERVICE_NAME, GuzzleIntegration::NAME);
            $span->setTag(Tag::HTTP_METHOD, $request->getMethod());
            $self->setUrlTag($span, $request);
            $span->setTag(Tag::RESOURCE_NAME, $method);
        };
    }

    /**
     * @param Span $span
     * @param mixed $request
     */
    protected function setUrlTag(Span $span, $request)
    {
        if (is_a($request, '\GuzzleHttp\Message\RequestInterface')) {
            $span->setTag(Tag::HTTP_URL, Urls::sanitize($request->getUrl()));
        } elseif (is_a($request, '\Psr\Http\Message\RequestInterface')) {
            $span->setTag(Tag::HTTP_URL, Urls::sanitize($request->getUri()));
        }
    }

    /**
     * @param Span $span
     * @param mixed $response
     */
    protected function setStatusCodeTag(Span $span, $response)
    {
        if (is_a($response, '\GuzzleHttp\Message\ResponseInterface')) {
            $span->setTag(Tag::HTTP_STATUS_CODE, Urls::sanitize($response->getStatusCode()), true);
        } elseif (is_a($response, '\Psr\Http\Message\ResponseInterface')) {
            $span->setTag(Tag::HTTP_STATUS_CODE, Urls::sanitize($response->getStatusCode()), true);
        } elseif (is_a($response, '\GuzzleHttp\Promise\Promise')) {
            $response->then(function ($response) use ($span) {
                $span->setTag(Tag::HTTP_STATUS_CODE, Urls::sanitize($response->getStatusCode()), true);
            });
        }
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

    /**
     * @param mixed $request
     * @return string[]
     */
    protected function extractRequestHeaders($request)
    {
        $headers = [];

        if (is_a($request, '\GuzzleHttp\Message\MessageInterface')
                || is_a($request, '\Psr\Http\Message\MessageInterface')) {
            // Associative array of header names to values
            $headers = $request->getHeaders();
        }

        return $headers;
    }

    /**
     * @param mixed $request
     * @param array $headers
     */
    protected function addRequestHeaders($request, $headers)
    {
        if (is_a($request, '\GuzzleHttp\Message\MessageInterface')) {
            $request->setHeaders($headers);
        } elseif (is_a($request, '\Psr\Http\Message\MessageInterface')) {
            foreach ($headers as $name => $value) {
                $request->withAddedHeader($name, $value);
            }
        }
    }
}
