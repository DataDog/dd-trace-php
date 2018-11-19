<?php

namespace DDTrace\Integrations\Guzzle\v6;

class GuzzleIntegration
{
    public static function load()
    {
        if (!extension_loaded('ddtrace')) {
            trigger_error('The ddtrace extension is required to instrument Guzzle tracing', E_USER_WARNING);
            return;
        }
        if (!class_exists('GuzzleHttp\Client')) {
            trigger_error('GuzzleHttp\Client is not loaded and cannot be instrumented', E_USER_WARNING);
            return;
        }

        // Psr\Http\Message\ResponseInterface GuzzleHttp\Client::request ( string $method [, string $uri, array $options ] )
        dd_trace('GuzzleHttp\Client', 'request', function (...$args) {
            $tracer = new GuzzleTracer($this, 'request', $args);
            $tracer->setTag('http.method', strtoupper($args[0]));
            return $tracer->trace();
        });

        // Psr\Http\Message\ResponseInterface GuzzleHttp\Client::send ( Psr\Http\Message\RequestInterface $request [, array $options ] )
        dd_trace('GuzzleHttp\Client', 'send', function (...$args) {
            $tracer = new GuzzleTracer($this, 'send', $args);
            $tracer->setTag('http.method', $args[0]->getMethod());
            return $tracer->trace();
        });
    }
}
