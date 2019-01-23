<?php

namespace DDTrace\Integrations\Guzzle\V5;

use DDTrace\Contracts\Span;
use DDTrace\Http\Urls;
use DDTrace\Integrations\Guzzle\AbstractGuzzleIntegrationLoader;
use DDTrace\Tag;

/**
 * Concrete integration loader for Guzzle V5
 */
final class GuzzleIntegrationLoader extends AbstractGuzzleIntegrationLoader
{
    /**
     * @return string
     */
    protected function getMethodToTrace()
    {
        return 'send';
    }

    /**
     * @param Span $span
     * @param \GuzzleHttp\Message\RequestInterface $request
     */
    protected function setUrlTag(Span $span, $request)
    {
        if (!is_a($request, '\GuzzleHttp\Message\RequestInterface')) {
            return;
        }
        $span->setTag(Tag::HTTP_URL, Urls::sanitize($request->getUrl()));
    }

    /**
     * @param Span $span
     * @param \GuzzleHttp\Message\ResponseInterface $response
     */
    protected function setStatusCodeTag(Span $span, $response)
    {
        if (is_a($response, '\GuzzleHttp\Message\ResponseInterface')) {
            $this->setStatusCodeTagFromResponse($span, $response);
        }
    }

    /**
     * @param Span $span
     * @param \GuzzleHttp\Message\ResponseInterface $response
     */
    private function setStatusCodeTagFromResponse(Span $span, $response)
    {
        $span->setTag(Tag::HTTP_STATUS_CODE, Urls::sanitize($response->getStatusCode()), true);
    }

    /**
     * @param \GuzzleHttp\Message\MessageInterface $request
     * @return string[]
     */
    protected function extractRequestHeaders($request)
    {
        if (!is_a($request, '\GuzzleHttp\Message\MessageInterface')) {
            return [];
        }

        // Associative array of header names to values
        return $request->getHeaders();
    }

    /**
     * @param \GuzzleHttp\Message\MessageInterface $request
     * @param array $headers
     */
    protected function addRequestHeaders($request, $headers)
    {
        $request->setHeaders($headers);
    }
}
