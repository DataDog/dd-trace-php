<?php

namespace DDTrace\Integrations\Guzzle\V5;

use DDTrace\Tags;
use DDTrace\Types;
use OpenTracing\Span;
use DDTrace\Integrations\Integration;

class GuzzleIntegration extends Integration
{
    const CLASS_NAME = 'GuzzleHttp\Client';

    protected static function loadIntegration()
    {
        self::traceMethod('send', function (Span $span, array $args) {
            $span->setTag('http.method', $args[0]->getMethod());
        });
    }

    public static function setDefaultTags(Span $span, $method)
    {
        parent::setDefaultTags($span, $method);
        $span->setTag(Tags\SPAN_TYPE, Types\GUZZLE);
        $span->setTag(Tags\SERVICE_NAME, 'guzzle');
    }
}
