<?php

namespace DDTrace\Encoders;

use DDTrace\Encoder;

final class Noop implements Encoder
{
    public function encodeTraces(array $traces)
    {
        return '';
    }

    public function getContentType()
    {
        return 'noop';
    }
}
