<?php

namespace DDTrace\Integrations\Guzzle\V6;

use DDTrace\Contracts\Span;
use DDTrace\Http\Urls;
use DDTrace\Integrations\Guzzle\AbstractGuzzleIntegrationLoader;
use DDTrace\Tag;

/**
 * Concrete integration loader for Guzzle V6
 */
final class GuzzleIntegrationLoader extends AbstractGuzzleIntegrationLoader
{
    /**
     * @return string
     */
    protected function getMethodToTrace()
    {
        return 'transfer';
    }

    /**
     * @param Span $span
     * @param \Psr\Http\Message\RequestInterface $request
     */
    protected function setUrlTag(Span $span, $request)
    {
        if (!is_a($request, '\Psr\Http\Message\RequestInterface')) {
            return;
        }
        $span->setTag(Tag::HTTP_URL, Urls::sanitize($request->getUri()));
    }

    /**
     * @param Span $span
     * @param \Psr\Http\Message\ResponseInterface|\GuzzleHttp\Promise\Promise $response
     */
    protected function setStatusCodeTag(Span $span, $response)
    {
        if (is_a($response, '\Psr\Http\Message\ResponseInterface')) {
            $this->setStatusCodeTagFromResponse($span, $response);
        } elseif (is_a($response, '\GuzzleHttp\Promise\Promise')) {
            $response->then(function ($result) use ($span) {
                $this->setStatusCodeTagFromResponse($span, $result);
            });
        }
    }

    /**
     * @param Span $span
     * @param \Psr\Http\Message\ResponseInterface $response
     */
    private function setStatusCodeTagFromResponse(Span $span, $response)
    {
        $span->setTag(Tag::HTTP_STATUS_CODE, Urls::sanitize($response->getStatusCode()), true);
    }

    /**
     * @param \Psr\Http\Message\MessageInterface $request
     * @return array
     */
    protected function extractRequestHeaders($request)
    {
        if (!is_a($request, '\Psr\Http\Message\MessageInterface')) {
            return [];
        }

        // Associative array of header names to values
        return $request->getHeaders();
    }

    /**
     * @param \Psr\Http\Message\ResponseInterface $request
     * @param array $headers
     */
    protected function addRequestHeaders($request, $headers)
    {
        if (!is_a($request, '\Psr\Http\Message\MessageInterface')) {
            return;
        }

        foreach ($headers as $name => $value) {
            $request->withAddedHeader($name, $value);
        }
    }
}
