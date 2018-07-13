<?php

namespace DDTrace;

interface Transport
{
    /**
     * @param Span[][] $traces
     */
    public function send(array $traces);

    /**
     * @param string $key
     * @param string $value
     * @return void
     */
    public function setHeader($key, $value);
}
