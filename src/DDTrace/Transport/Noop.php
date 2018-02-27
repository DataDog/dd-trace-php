<?php

namespace DDTrace\Transport;

use DDTrace\Transport;
use GuzzleHttp\Psr7\Response;

final class Noop implements Transport
{
    public function send(array $traces)
    {
        return new Response();
    }

    public function setHeader($key, $value)
    {
    }
}
