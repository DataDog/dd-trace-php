<?php

namespace DDTrace\Integrations\Guzzle\V5;

use DDTrace\Tags;
use DDTrace\Types;
use OpenTracing\Span;
use DDTrace\Http\Urls;
use DDTrace\Integrations\Integration;
use GuzzleHttp\Message\ResponseInterface;

class GuzzleIntegration extends Integration
{
    const CLASS_NAME = 'GuzzleHttp\Client';

    protected static function loadIntegration()
    {
        self::traceMethod('send', function (Span $span, array $args) {
            $span->setTag('http.method', $args[0]->getMethod());
            $span->setTag('http.url', Urls::sanitize($args[0]->getUrl()));
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
        $span->setTag(Tags\SPAN_TYPE, Types\HTTP_CLIENT);
        $span->setTag(Tags\SERVICE_NAME, 'guzzle');
    }
}
