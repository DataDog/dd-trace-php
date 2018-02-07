<?php

namespace DDTrace;

use DDTrace\Transport\Noop;

final class Tracer
{
    /**
     * @var array
     */
    private $traces = [];

    /**
     * @var Transport
     */
    private $transport;

    public function __construct(Transport $transport)
    {
        $this->transport = $transport;
    }

    public static function noop()
    {
        return new self(new Noop);
    }

    /**
     * @param Span $span
     */
    public function record(Span $span)
    {
        $this->traces[$span->getTraceId()][] = $span;
    }

    /**
     * @return void
     */
    public function flush()
    {
        $this->transport->send($this->traces);
    }
}
