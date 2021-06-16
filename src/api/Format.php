<?php

namespace DDTrace;

class Format
{
    /**
     * Used a (single) arbitrary binary blob representing a SpanContext
     *
     * For both Tracer::inject() and Tracer::extract() the carrier must be a `string`.
     */
    const BINARY = 'binary';

    /**
     * Used for an arbitrary string-to-string map with an unrestricted character set for both keys and values
     *
     * Unlike `HTTP_HEADERS`, the `TEXT_MAP` format does not restrict the key or
     * value character sets in any way.
     *
     * For both Tracer::inject() and Tracer::extract() the carrier must be a `array|ArrayObject`.
     */
    const TEXT_MAP = 'text_map';

    /**
     * Used for a string-to-string map with keys and values that are suitable for use in HTTP headers (a la RFC 7230.
     * In practice, since there is such "diversity" in the way that HTTP headers are treated in the wild, it is strongly
     * recommended that Tracer implementations use a limited HTTP header key space and escape values conservatively.
     *
     * Unlike `TEXT_MAP`, the `HTTP_HEADERS` format requires that the keys and values be valid as HTTP headers as-is
     * (i.e., character casing may be unstable and special characters are disallowed in keys, values should be
     * URL-escaped, etc).
     *
     * For both Tracer::inject() and Tracer::extract() the carrier must be a `array|ArrayObject`.
     *
     * For example, Tracer::inject():
     *
     *    $headers = []
     *    $tracer->inject($span->getContext(), Format::HTTP_HEADERS, $headers)
     *    $request = new GuzzleHttp\Psr7\Request($uri, $body, $headers);
     *
     * Or Tracer::extract():
     *
     *    $headers = $request->getHeaders()
     *    $clientContext = $tracer->extract(Formats::HTTP_HEADERS, $headers)
     *
     * @see http://www.php-fig.org/psr/psr-7/#12-http-headers
     * @see http://php.net/manual/en/function.getallheaders.php
     */
    const HTTP_HEADERS = 'http_headers';

    /**
     * A propagator that handles curl style http headers arrays.
     */
    const CURL_HTTP_HEADERS = 'curl_http_headers';
}
