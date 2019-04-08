<?php

namespace DDTrace\Tests;

use DDTrace\Contracts\Tracer;
use DDTrace\Transport;

class DebugTransport implements Transport
{
    /**
     * Holds traces sent
     *
     * @var array
     */
    private $traces = array();

    /***
     * Holds set headers
     *
     * @var array
     */
    private $headers = array();

    public function send(Tracer $tracer)
    {
        $this->traces = array_merge($this->traces, $tracer->getTracesAsArray());
    }

    /**
     * @param string $key
     * @param string $value
     * @return void
     */
    public function setHeader($key, $value)
    {
        array_push($headers, [$key => $value]);
    }

    /**
     * @return array()
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    public function getTraces()
    {
        return $this->traces;
    }
}
