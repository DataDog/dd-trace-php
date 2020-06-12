<?php

namespace DDTrace;

use DDTrace\Contracts\Tracer as TracerInterface;

interface Transport
{
    /**
     * @param TracerInterface $tracer
     */
    public function send(TracerInterface $tracer);

    /**
     * @param string $key
     * @param string $value
     * @return void
     */
    public function setHeader($key, $value);
}
