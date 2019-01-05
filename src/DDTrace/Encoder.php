<?php

namespace DDTrace;

use \DDTrace\Contracts\Span;

interface Encoder
{
    /**
     * @param Span[][]|array $traces
     * @return string
     */
    public function encodeTraces(array $traces);

    /**
     * @return string
     */
    public function getContentType();
}
