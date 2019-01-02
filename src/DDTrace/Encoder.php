<?php

namespace DDTrace;

interface Encoder
{
    /**
     * @param Span[][]|array $traces
     * @return string|\Psr\Http\Message\StreamInterface
     */
    public function encodeTraces(array $traces);

    /**
     * @return string
     */
    public function getContentType();
}
