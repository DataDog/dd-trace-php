<?php

namespace DDTrace\Transport;

use DDTrace\Transport;

final class Noop implements Transport
{
    public function send(array $traces)
    {
    }

    public function setHeader($key, $value)
    {
    }
}
