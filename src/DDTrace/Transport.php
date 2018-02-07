<?php

namespace DDTrace;

use Psr\Http\Message\ResponseInterface;

interface Transport
{
    /**
     * @param Span[][] $traces
     * @return ResponseInterface
     */
    public function send(array $traces);

    /**
     * @param string $key
     * @param string $value
     * @return void
     */
    public function setHeader($key, $value);
}
