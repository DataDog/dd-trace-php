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
        /* Trace/span ID's cannot be cast with (int)
           since distributed traces can contain 64-bit
           unsigned int's that overflow in userland.
           Because of this, ID's are sent as strings to
           json_encode() and "cast" to int's after
           serialization.

           Moral of the story:
                Use the MessagePack encoder. */
        return preg_replace(
            '/"(trace_id|span_id|parent_id)":"(\d+)"/',
            '"$1":$2',
            $json
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getContentType()
    {
        return 'application/json';
    }
}
