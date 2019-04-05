<?php

namespace DDTrace\Encoders;

use DDTrace\Contracts\Tracer;
use DDTrace\Encoder;
use DDTrace\Log\LoggingTrait;

final class Json implements Encoder
{
    use LoggingTrait;

    /**
     * {@inheritdoc}
     */
    public function encodeTraces(Tracer $tracer)
    {
        $json = json_encode($tracer->getTracesAsArray());
        if (false === $json) {
            self::logDebug('Failed to json-encode trace: ' . json_last_error_msg());
            return '[[]]';
        }
        return $json;
    }

    /**
     * {@inheritdoc}
     */
    public function getContentType()
    {
        return 'application/json';
    }
}
