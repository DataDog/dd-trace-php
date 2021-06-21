<?php

namespace DDTrace\Transport;

use DDTrace\Contracts\Tracer as TracerInterface;
use DDTrace\Transport;

final class Internal implements Transport
{
    public function send(TracerInterface $tracer)
    {
        if (!\dd_trace_env_config('DD_TRACE_GENERATE_ROOT_SPAN')) {
            // @phpstan-ignore-next-line
            \DDTrace\flush();
        }
    }

    public function setHeader($key, $value)
    {
        // No-Op, background sender does not accept headers
    }
}
