<?php

namespace DDTrace\Integrations\Guzzle;

use DDTrace\Format;
use DDTrace\GlobalTracer;
use DDTrace\Http\Urls;
use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use GuzzleHttp;

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

        if (\PHP_VERSION_ID < 50500) {
            \DDTrace\hook_method(
                'GuzzleHttp\\Client',
                '__construct',
                null,
                function (GuzzleHttp\Client $client, $s, $a) {
                    if (!\method_exists($client, 'getEmitter')) {
                        // must not be Guzzle 5
                        return;
                    }
                    $emitter = $client->getEmitter();
                    $emitter->once('before', function (GuzzleHttp\Event\EventInterface $event) {
                        if (!$event instanceof GuzzleHttp\Event\AbstractRequestEvent) {
                            return;
                        }
                        if (!\ddtrace_config_distributed_tracing_enabled()) {
                            return;
                        }
                        /** @var GuzzleHttp\Event\AbstractRequestEvent $event */
                        $request = $event->getRequest();
                        $headers = [];
                        \DDTrace\Bridge\inject_distributed_tracing_headers(Format::TEXT_MAP, $headers);
                        $request->addHeaders($headers);
                    });
                }
            );
        }

        /* Until we support both pre- and post- hooks on the same function, do
         * not send distributed tracing headers; curl will almost guaranteed do
         * it for us anyway. Just do a post-hook to get the response.
         */
        \DDTrace\trace_method(
            'GuzzleHttp\Client',
            'send',
            function (SpanData $span, $args, $retval) use ($integration) {
                $span->resource = 'send';
                $span->name = 'GuzzleHttp\Client.send';
                $span->service = 'guzzle';
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

        \DDTrace\trace_method(
            'GuzzleHttp\Client',
            'transfer',
            function (SpanData $span, $args, $retval) use ($integration) {
                $span->resource = 'transfer';
                $span->name = 'GuzzleHttp\Client.transfer';
                $span->service = 'guzzle';
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
            if (\ddtrace_config_http_client_split_by_domain_enabled()) {
                $span->service = Urls::hostnameForTag($url);
            }
            $span->meta[Tag::HTTP_METHOD] = $request->getMethod();
            $span->meta[Tag::HTTP_URL] = Urls::sanitize($url);
        } elseif (\is_a($request, 'GuzzleHttp\Message\RequestInterface')) {
            /** @var \GuzzleHttp\Message\RequestInterface $request */
            $url = $request->getUrl();
            if (\ddtrace_config_http_client_split_by_domain_enabled()) {
                $span->service = Urls::hostnameForTag($url);
            }
            $span->meta[Tag::HTTP_METHOD] = $request->getMethod();
            $span->meta[Tag::HTTP_URL] = Urls::sanitize($url);
        }
    }
}
