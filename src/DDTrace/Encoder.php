<?php

namespace DDTrace;

use DDTrace\Contracts\Span as SpanInterface;

interface Encoder
{
    /**
     * @param SpanInterface[][]|array $traces
     * @return string
     */
    public function encodeTraces(array $traces);

    /**
     * @return string
     */
    public function getContentType();
}
