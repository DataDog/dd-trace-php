<?php

namespace DDTrace\Integrations\Guzzle;

use DDTrace\Configuration;
use DDTrace\GlobalTracer;
use DDTrace\Http\Urls;
use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

class GuzzleSandboxedIntegration extends SandboxedIntegration
{

    const NAME = 'guzzle';

    public function getName()
    {
        return self::NAME;
    }

    public function init()
    {
        if (!self::shouldLoad(self::NAME)) {
            return SandboxedIntegration::NOT_LOADED;
        }

        $tracer = GlobalTracer::get();
        $rootScope = $tracer->getRootScope();
        if (!$rootScope) {
            return SandboxedIntegration::NOT_LOADED;
        }

        $integration = $this;
        $service = \ddtrace_config_app_name(self::NAME);

        /* Until we support both pre- and post- hooks on the same function, do
         * not send distributed tracing headers; curl will almost guaranteed do
         * it for us anyway. Just do a post-hook to get the response.
         */
        \dd_trace_method(
            'GuzzleHttp\Client',
            'send',
            function (SpanData $span, $args, $retval) use ($integration, $service) {
                $span->resource = 'send';
                $span->name = 'GuzzleHttp\Client.send';
                $span->service = $service;
                $span->type = Type::HTTP_CLIENT;

                if (isset($args[0])) {
                    $integration->addRequestInfo($span, $args[0]);
                }

                if (isset($retval)) {
                    $response = $retval;
                    if (\is_a($response, 'GuzzleHttp\Message\ResponseInterface')) {
                        /** @var \GuzzleHttp\Message\ResponseInterface $response */
                        $span->meta[Tag::HTTP_STATUS_CODE] = $response->getStatusCode();
                    } elseif (\is_a($response, 'Psr\Http\Message\ResponseInterface')) {
                        /** @var \Psr\Http\Message\ResponseInterface $response */
                        $span->meta[Tag::HTTP_STATUS_CODE] = $response->getStatusCode();
                    } elseif (\is_a($response, 'GuzzleHttp\Promise\PromiseInterface')) {
                        /** @var \GuzzleHttp\Promise\PromiseInterface $response */
                        $response->then(function (\Psr\Http\Message\ResponseInterface $response) use ($span) {
                            $span->meta[Tag::HTTP_STATUS_CODE] = $response->getStatusCode();
                        });
                    }
                }
            }
        );

        \dd_trace_method(
            'GuzzleHttp\Client',
            'transfer',
            function (SpanData $span, $args, $retval) use ($integration, $service) {
                $span->resource = 'transfer';
                $span->name = 'GuzzleHttp\Client.transfer';
                $span->service = $service;
                $span->type = Type::HTTP_CLIENT;

                if (isset($args[0])) {
                    $integration->addRequestInfo($span, $args[0]);
                }
                if (isset($retval)) {
                    $response = $retval;
                    if (\is_a($response, 'GuzzleHttp\Promise\PromiseInterface')) {
                        /** @var \GuzzleHttp\Promise\PromiseInterface $response */
                        $response->then(function (\Psr\Http\Message\ResponseInterface $response) use ($span) {
                            $span->meta[Tag::HTTP_STATUS_CODE] = $response->getStatusCode();
                        });
                    }
                }
            }
        );

        return SandboxedIntegration::LOADED;
    }

    public function addRequestInfo(SpanData $span, $request)
    {
        if (\is_a($request, 'Psr\Http\Message\RequestInterface')) {
            /** @var \Psr\Http\Message\RequestInterface $request */
            $url = $request->getUri();
            if (Configuration::get()->isHttpClientSplitByDomain()) {
                $span->service = Urls::hostnameForTag($url);
            }
            $span->meta[Tag::HTTP_METHOD] = $request->getMethod();
            $span->meta[Tag::HTTP_URL] = Urls::sanitize($url);
        } elseif (\is_a($request, 'GuzzleHttp\Message\RequestInterface')) {
            /** @var \GuzzleHttp\Message\RequestInterface $request */
            $url = $request->getUrl();
            if (Configuration::get()->isHttpClientSplitByDomain()) {
                $span->service = Urls::hostnameForTag($url);
            }
            $span->meta[Tag::HTTP_METHOD] = $request->getMethod();
            $span->meta[Tag::HTTP_URL] = Urls::sanitize($url);
        }
    }
}
