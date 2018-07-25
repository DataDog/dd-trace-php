<?php

namespace DDTrace\Transport;

use DDTrace\Encoder;
use DDTrace\Transport;

final class Stdout implements Transport
{
    /**
     * @var Encoder
     */
    private $encoder;

    /**
     * @var array
     */
    private $headers = [];

    public function __construct(Encoder $encoder)
    {
        $this->encoder = $encoder;
    }

    public function send(array $traces)
    {
        var_dump($this->headers);
        echo $this->encoder->encodeTraces($traces) . "\n";
    }

    public function setHeader($key, $value)
    {
        $this->headers[(string) $key] = (string) $value;
    }
}
