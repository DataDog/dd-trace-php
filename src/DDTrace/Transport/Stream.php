<?php

namespace DDTrace\Transport;

use DDTrace\Contracts\Tracer;
use DDTrace\Encoder;
use DDTrace\Transport;

final class Stream implements Transport
{
    /**
     * @var Encoder
     */
    private $encoder;

    /**
     * @var resource
     */
    private $stream;

    /**
     * @var array
     */
    private $headers = [];

    public function __construct(Encoder $encoder, $stream = null)
    {
        $this->encoder = $encoder;
        $this->stream = $stream ?: \fopen('php://output', 'w');
    }

    /**
     * {@inheritdoc}
     */
    public function send(Tracer $tracer)
    {
        \fwrite($this->stream, '{"headers": ');
        \fwrite($this->stream, \json_encode($this->headers));
        \fwrite($this->stream, ', "traces": ');
        \fwrite($this->stream, $this->encoder->encodeTraces($tracer));
        \fwrite($this->stream, '}');
        \fwrite($this->stream, PHP_EOL);
    }

    public function setHeader($key, $value)
    {
        $this->headers[(string) $key] = (string) $value;
    }
}
