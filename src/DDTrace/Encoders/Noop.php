<?php

namespace DDTrace\Encoders;

use DDTrace\Contracts\Tracer;
use DDTrace\Encoder;

final class Noop implements Encoder
{
    /**
     * {@inheritdoc}
     */
    public function encodeTraces(Tracer $tracer)
    {
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function getContentType()
    {
        return 'noop';
    }
}
