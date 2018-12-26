<?php

namespace DDTrace;

use DDTrace\Contracts\Span as SpanInterface;
use Psr\Http\Message\StreamInterface;

interface Encoder
{
    /**
     * @param SpanInterface[][]|array $traces
     * @return string|StreamInterface
     */
    public function encodeTraces(array $traces);

    /**
     * @return string
     */
    public function getContentType();
}
