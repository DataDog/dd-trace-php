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
        $tracesAsArray = $tracer->getTracesAsArray();
        if (\defined('JSON_PRESERVE_ZERO_FRACTION')) {
            // Only available for PHP 5.6.6+
            $json = json_encode($tracesAsArray, JSON_PRESERVE_ZERO_FRACTION);
        } else {
            $json = json_encode($tracesAsArray);
        }
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
