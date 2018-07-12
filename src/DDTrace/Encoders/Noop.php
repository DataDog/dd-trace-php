<?php

namespace DDTrace\Encoders;

use DDTrace\Encoder;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

final class Noop implements Encoder
{
    use LoggerAwareTrait;

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
