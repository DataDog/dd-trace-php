<?php

namespace DDTrace\Integrations\Guzzle\V5;

use DDTrace\Configuration;
use DDTrace\Format;
use DDTrace\Tag;
use DDTrace\Span;
use DDTrace\Type;
use DDTrace\GlobalTracer;
use DDTrace\Http\Urls;
use DDTrace\Integrations\Integration;
use DDTrace\Util\Environment;
use GuzzleHttp\Message\ResponseInterface;

class GuzzleIntegration extends Integration
{
    const NAME = 'guzzle';
    const CLASS_NAME = 'GuzzleHttp\Client';

    protected static function loadIntegration()
    {
        if (Environment::matchesPhpVersion('5.4')) {
            return;
        }

        self::traceMethod('send', function (Span $span, array $args) {
            list($request) = $args;
            GuzzleIntegration::injectDistributedTracingHeaders($request, $span);
            $span->setTag('http.method', $request->getMethod());
            $span->setTag('http.url', Urls::sanitize($request->getUrl()));
        }, function (Span $span, $response) {
            if (!$response instanceof ResponseInterface) {
                return;
            }
            $span->setTag('http.status_code', $response->getStatusCode());
        });
    }

    public static function setDefaultTags(Span $span, $method)
    {
        parent::setDefaultTags($span, $method);
        $span->setTag(Tag::SPAN_TYPE, Type::HTTP_CLIENT);
        $span->setTag(Tag::SERVICE_NAME, 'guzzle');
    }

    /**
     * @param \GuzzleHttp\Message\MessageInterface $request
     * @param Span $span
     */
    public static function injectDistributedTracingHeaders($request, $span)
    {
        if (!Configuration::get()->isDistributedTracingEnabled()) {
            return;
        }

        if (!is_subclass_of($request, '\GuzzleHttp\Message\MessageInterface')) {
            return;
        }

        // Associative array of header names to values
        $headers = $request->getHeaders();

        $context = $span->getContext();
        $tracer = GlobalTracer::get();
        $tracer->inject($context, Format::HTTP_HEADERS, $headers);
        $request->setHeaders($headers);
    }
}
