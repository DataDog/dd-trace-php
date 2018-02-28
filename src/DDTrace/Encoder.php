<?php

namespace DDTrace;

use Psr\Http\Message\StreamInterface;

interface Encoder
{
    /**
     * @param Span[][]|array $traces
     * @return string|StreamInterface
     */
    public function encodeTraces(array $traces);

    /**
     * @return string
     */
    public function getContentType();
}
