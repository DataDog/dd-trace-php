<?php

namespace DDTrace\Transport;

use DDTrace\Contracts\Tracer;
use DDTrace\Transport;

final class Noop implements Transport
{
    /**
     * {@inheritdoc}
     */
    public function send(Tracer $tracer)
    {
    }

    public function setHeader($key, $value)
    {
    }
}
