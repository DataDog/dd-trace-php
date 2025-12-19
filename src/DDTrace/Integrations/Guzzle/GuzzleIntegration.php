<?php

namespace DDTrace\Integrations\Guzzle;

use DDTrace\HookData;
use DDTrace\Http\Urls;
use DDTrace\Integrations\HttpClientIntegrationHelper;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use GuzzleHttp;

class GuzzleIntegration extends Integration
{
    const NAME = 'guzzle';

    public static function handlePromiseResponse($response, SpanData $span)
    {
        if ($response->getState() === \GuzzleHttp\Promise\PromiseInterface::FULFILLED) {
            $fulfilledResponse = $response->wait();
            if ($fulfilledResponse instanceof \Psr\Http\Message\ResponseInterface) {
                $span->meta[Tag::HTTP_STATUS_CODE] = $fulfilledResponse->getStatusCode();
            }
        } else {
            /** @var \GuzzleHttp\Promise\PromiseInterface $response */
            $response->then(static function (\Psr\Http\Message\ResponseInterface $response) use ($span) {
                $statusCode = $response->getStatusCode();
                $span->meta[Tag::HTTP_STATUS_CODE] = $statusCode;
                HttpClientIntegrationHelper::setClientError($span, $statusCode, $response->getReasonPhrase());
            });
        }
    }

    public static function init(): int
    {
        \DDTrace\install_hook(
            'GuzzleHttp\Client::transfer',
            static function (HookData $hook) {
                // Note: We must ALWAYS call overrideArguments() to prevent JIT compilation issues.
                // See ext/hook/uhook.c: "hooks wishing to override args must do so unconditionally"

                $modified = false;

                if (isset($hook->args[0])) {
                    $request = $hook->args[0];

                    if ($request instanceof \Psr\Http\Message\RequestInterface) {
                        $dtHeaders = \DDTrace\generate_distributed_tracing_headers();

                        if (!empty($dtHeaders)) {
                            foreach ($dtHeaders as $name => $value) {
                                if (!$request->hasHeader($name)) {
                                    $request = $request->withHeader($name, $value);
                                    $modified = true;
                                }
                            }

                            if ($modified) {
                                $hook->args[0] = $request;
                            }
                        }
                    }
                }

                // CRITICAL: Always call overrideArguments to prevent JIT from breaking header injection
                $hook->overrideArguments($hook->args);
            },
            static function (HookData $hook) {
                $span = $hook->span();
                if (!$span) {
                    return;
                }

                $span->resource = 'transfer';
                $span->name = 'GuzzleHttp\Client.transfer';
                Integration::handleInternalSpanServiceName($span, self::NAME);
                $span->type = Type::HTTP_CLIENT;
                $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;
                $span->meta[Tag::COMPONENT] = self::NAME;
                $span->peerServiceSources = HttpClientIntegrationHelper::PEER_SERVICE_SOURCES;

                if (isset($hook->args[0])) {
                    self::addRequestInfo($span, $hook->args[0]);
                }

                if (isset($hook->returned)) {
                    $response = $hook->returned;
                    if (\is_a($response, 'GuzzleHttp\Promise\PromiseInterface')) {
                        self::handlePromiseResponse($response, $span);
                    }
                }
            }
        );

        \DDTrace\trace_method(
            'GuzzleHttp\Client',
            'send',
            static function (SpanData $span, $args, $retval) {
                $span->resource = 'send';
                $span->name = 'GuzzleHttp\Client.send';
                Integration::handleInternalSpanServiceName($span, self::NAME);
                $span->type = Type::HTTP_CLIENT;
                $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;
                $span->meta[Tag::COMPONENT] = self::NAME;

                if (
                    \defined('GuzzleHttp\ClientInterface::VERSION')
                    && substr(\GuzzleHttp\ClientInterface::VERSION, 0, 2) === '5.'
                ) {
                    $span->peerServiceSources = HttpClientIntegrationHelper::PEER_SERVICE_SOURCES;
                }

                if (isset($args[0])) {
                    self::addRequestInfo($span, $args[0]);
                }

                if (isset($retval)) {
                    $response = $retval;
                    if (\is_a($response, 'GuzzleHttp\Message\ResponseInterface')) {
                        /** @var \GuzzleHttp\Message\ResponseInterface $response */
                        $statusCode = $response->getStatusCode();
                        $span->meta[Tag::HTTP_STATUS_CODE] = $statusCode;
                        HttpClientIntegrationHelper::setClientError($span, $statusCode, $response->getReasonPhrase());
                    } elseif (\is_a($response, 'Psr\Http\Message\ResponseInterface')) {
                        /** @var \Psr\Http\Message\ResponseInterface $response */
                        $statusCode = $response->getStatusCode();
                        $span->meta[Tag::HTTP_STATUS_CODE] = $statusCode;
                        HttpClientIntegrationHelper::setClientError($span, $statusCode, $response->getReasonPhrase());
                    } elseif (\is_a($response, 'GuzzleHttp\Promise\PromiseInterface')) {
                        self::handlePromiseResponse($response, $span);
                    }
                }
            }
        );

        return Integration::LOADED;
    }

    public static function addRequestInfo(SpanData $span, $request)
    {
        if ($request instanceof \Psr\Http\Message\RequestInterface) {
            $url = $request->getUri();
            $host = Urls::hostname($url);
            $span->meta[Tag::NETWORK_DESTINATION_NAME] = $host;

            if (\dd_trace_env_config("DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN")) {
                $span->service = Urls::hostnameForTag($url);
            }
            $span->meta[Tag::HTTP_METHOD] = $request->getMethod();

            if (!array_key_exists(Tag::HTTP_URL, $span->meta)) {
                $span->meta[Tag::HTTP_URL] = \DDTrace\Util\Normalizer::urlSanitize($url);
            }
        } elseif ($request instanceof \GuzzleHttp\Message\RequestInterface) {
            $url = $request->getUrl();
            $host = Urls::hostname($url);
            if ($host) {
                $span->meta[Tag::NETWORK_DESTINATION_NAME] = $host;
            }
            if (\dd_trace_env_config("DD_TRACE_HTTP_CLIENT_SPLIT_BY_DOMAIN")) {
                $span->service = Urls::hostnameForTag($url);
            }
            $span->meta[Tag::HTTP_METHOD] = $request->getMethod();

            if (!array_key_exists(Tag::HTTP_URL, $span->meta)) {
                $span->meta[Tag::HTTP_URL] = \DDTrace\Util\Normalizer::urlSanitize($url);
            }
        }
    }
}
