<?php

namespace DDTrace\Integrations\Guzzle;

use DDTrace\Http\Urls;
use DDTrace\Integrations\HttpClientIntegrationHelper;
use DDTrace\Integrations\Integration;
use DDTrace\Integrations\SpanTaxonomy;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use GuzzleHttp;

class GuzzleIntegration extends Integration
{
    const NAME = 'guzzle';

    public function getName()
    {
        return self::NAME;
    }

    public function init()
    {
        if (!self::shouldLoad(self::NAME)) {
            return Integration::NOT_LOADED;
        }

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
                SpanTaxonomy::instance()->handleInternalSpanServiceName($span, GuzzleIntegration::NAME);
                $span->type = Type::HTTP_CLIENT;
                $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;
                $span->meta[Tag::COMPONENT] = GuzzleIntegration::NAME;

                if (
                    \PHP_MAJOR_VERSION > 5
                    && \defined('GuzzleHttp\ClientInterface::VERSION')
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
                SpanTaxonomy::instance()->handleInternalSpanServiceName($span, GuzzleIntegration::NAME);
                $span->type = Type::HTTP_CLIENT;
                $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;
                $span->meta[Tag::COMPONENT] = GuzzleIntegration::NAME;

                if (\PHP_MAJOR_VERSION > 5) {
                    $span->peerServiceSources = HttpClientIntegrationHelper::PEER_SERVICE_SOURCES;
                }

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

        return Integration::LOADED;
    }

    public function addRequestInfo(SpanData $span, $request)
    {
        if (\is_a($request, 'Psr\Http\Message\RequestInterface')) {
            /** @var \Psr\Http\Message\RequestInterface $request */
            $url = $request->getUri();
            $host = Urls::hostname($url);
            $span->meta[Tag::NETWORK_DESTINATION_NAME] = $host;

            if (\DDTrace\Util\Runtime::getBoolIni("datadog.trace.http_client_split_by_domain")) {
                $span->service = Urls::hostnameForTag($url);
            }
            $span->meta[Tag::HTTP_METHOD] = $request->getMethod();

            if (!array_key_exists(Tag::HTTP_URL, $span->meta)) {
                $span->meta[Tag::HTTP_URL] = \DDTrace\Util\Normalizer::urlSanitize($url);
            }
        } elseif (\is_a($request, 'GuzzleHttp\Message\RequestInterface')) {
            /** @var \GuzzleHttp\Message\RequestInterface $request */
            $url = $request->getUrl();
            $host = Urls::hostname($url);
            if ($host) {
                $span->meta[Tag::NETWORK_DESTINATION_NAME] = $host;
            }
            if (\DDTrace\Util\Runtime::getBoolIni("datadog.trace.http_client_split_by_domain")) {
                $span->service = Urls::hostnameForTag($url);
            }
            $span->meta[Tag::HTTP_METHOD] = $request->getMethod();

            if (!array_key_exists(Tag::HTTP_URL, $span->meta)) {
                $span->meta[Tag::HTTP_URL] = \DDTrace\Util\Normalizer::urlSanitize($url);
            }
        }
    }
}
