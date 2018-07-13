<?php

namespace DDTrace\Encoders;

use DDTrace\Encoder;

final class Noop implements Encoder
{
    /**
     * {@inheritdoc}
     */
    public function encodeTraces(array $traces)
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
