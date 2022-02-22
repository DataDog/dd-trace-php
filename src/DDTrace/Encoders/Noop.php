<?php

namespace DDTrace\Encoders;

use DDTrace\Contracts\Tracer;
use DDTrace\Encoder;

/** @deprecated Obsoleted by moving related code to internal. */
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
