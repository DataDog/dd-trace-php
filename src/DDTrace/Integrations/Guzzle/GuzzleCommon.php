<?php

namespace DDTrace\Integrations\Guzzle;

use DDTrace\Configuration;
use DDTrace\Contracts\Span;
use DDTrace\Format;
use DDTrace\GlobalTracer;
use DDTrace\SpanData;
use DDTrace\Tag;

class GuzzleCommon
{
    /**
     * @param Span $span
     * @param mixed $response
     */
    public static function setStatusCodeTag(Span $span, $response)
    {
        if (is_a($response, '\GuzzleHttp\Message\ResponseInterface')) {
            $span->setTag(Tag::HTTP_STATUS_CODE, $response->getStatusCode(), true);
        } elseif (is_a($response, '\Psr\Http\Message\ResponseInterface')) {
            $span->setTag(Tag::HTTP_STATUS_CODE, $response->getStatusCode(), true);
        } elseif (is_a($response, '\GuzzleHttp\Promise\Promise')) {
            $response->then(function ($response) use ($span) {
                $span->setTag(Tag::HTTP_STATUS_CODE, $response->getStatusCode(), true);
            });
        }
    }

    /**
     * @param mixed $response
     */
    public static function setSpanDataStatusCodeTag(SpanData $span, $response)
    {
        if (is_a($response, '\GuzzleHttp\Message\ResponseInterface')) {
            $span->meta[Tag::HTTP_STATUS_CODE] = (string)$response->getStatusCode();
        } elseif (is_a($response, '\Psr\Http\Message\ResponseInterface')) {
            $span->meta[Tag::HTTP_STATUS_CODE] =  (string)$response->getStatusCode();
        } elseif (is_a($response, '\GuzzleHttp\Promise\Promise')) {
            $response->then(function ($response) use ($span) {
                $span->meta[Tag::HTTP_STATUS_CODE] = (string)$response->getStatusCode();
            });
        }
    }

    /**
     * @param mixed $request
     * @return string|null
     */
    public static function getRequestUrl($request)
    {
        $url = null;
        if (is_a($request, '\GuzzleHttp\Message\RequestInterface')) {
            $url = (string) $request->getUrl();
        } elseif (is_a($request, '\Psr\Http\Message\RequestInterface')) {
            $url = (string) $request->getUri();
        }

        return $url;
    }

    /**
     * @param mixed $request
     * @param array $headers
     */
    public static function addRequestHeaders($request, $headers)
    {
        if (is_a($request, '\GuzzleHttp\Message\MessageInterface')) {
            $request->setHeaders($headers);
        } elseif (is_a($request, '\Psr\Http\Message\MessageInterface')) {
            foreach ($headers as $name => $value) {
                $request->withAddedHeader($name, $value);
            }
        }
    }

    /**
     * @param mixed $request
     * @return string[]
     */
    public static function extractRequestHeaders($request)
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
     * @param Span $span
     */
    public static function applyDistributedTracingHeaders(Span $span, $request)
    {
        if (!Configuration::get()->isDistributedTracingEnabled()) {
            return;
        }

        $headers = self::extractRequestHeaders($request);

        $context = $span->getContext();
        $tracer = GlobalTracer::get();
        $tracer->inject($context, Format::HTTP_HEADERS, $headers);
        self::addRequestHeaders($request, $headers);
    }

    /**
     */
    public static function buildDistributedTracingHeaders()
    {
        $headers = [];

        if (!Configuration::get()->isDistributedTracingEnabled()) {
            return $headers;
        }

        $tracer = GlobalTracer::get();
        $span = $tracer->getActiveSpan();
        if (null == $span) {
            return $headers;
        }

        $tracer->inject($span->getContext(), Format::HTTP_HEADERS, $headers);
        return $headers;
    }
}
