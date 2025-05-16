<?php

namespace DDTrace\Integrations\Guzzle;

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

    public function init(): int
    {
        $integration = $this;

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
                Integration::handleInternalSpanServiceName($span, GuzzleIntegration::NAME);
                $span->type = Type::HTTP_CLIENT;
                $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;
                $span->meta[Tag::COMPONENT] = GuzzleIntegration::NAME;

                if (
                    \defined('GuzzleHttp\ClientInterface::VERSION')
                    && substr(\GuzzleHttp\ClientInterface::VERSION, 0, 2) === '5.'
                ) {
                    // On Guzzle 6+, we do not need to generate peer.service for the send span,
                    // as the terminal span is 'transfer'
                    $span->peerServiceSources = HttpClientIntegrationHelper::PEER_SERVICE_SOURCES;
                }

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
                Integration::handleInternalSpanServiceName($span, GuzzleIntegration::NAME);
                $span->type = Type::HTTP_CLIENT;
                $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;
                $span->meta[Tag::COMPONENT] = GuzzleIntegration::NAME;
                $span->peerServiceSources = HttpClientIntegrationHelper::PEER_SERVICE_SOURCES;

                if (isset($args[0])) {
                    $integration->addRequestInfo($span, $args[0]);
                }
                if (isset($retval)) {
                    $response = $retval;
                    if (\is_a($response, 'GuzzleHttp\Promise\PromiseInterface')) {
                        if ($response->getState() === \GuzzleHttp\Promise\PromiseInterface::FULFILLED) {
                            $fulfilledResponse = $response->wait();
                            if ($fulfilledResponse instanceof \Psr\Http\Message\ResponseInterface) {
                                $span->meta[Tag::HTTP_STATUS_CODE] = $fulfilledResponse->getStatusCode();
                            }
                        } else {
                            /** @var \GuzzleHttp\Promise\PromiseInterface $response */
                            $response->then(function (\Psr\Http\Message\ResponseInterface $response) use ($span) {
                                $span->meta[Tag::HTTP_STATUS_CODE] = $response->getStatusCode();
                            });
                        }
                    }
                }
            }
        );

        return Integration::LOADED;
    }

    public function addRequestInfo(SpanData $span, $request)
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
