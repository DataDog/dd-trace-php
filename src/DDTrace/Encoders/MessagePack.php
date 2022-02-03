<?php

namespace DDTrace\Encoders;

use DDTrace\Contracts\Tracer;
use DDTrace\Encoder;
use DDTrace\Log\LoggingTrait;

/** @deprecated Obsoleted by moving related code to internal. */
final class MessagePack implements Encoder
{
    use LoggingTrait;

    /**
     * {@inheritdoc}
     */
    public function encodeTraces(Tracer $tracer)
    {
        $messagePack = \dd_trace_serialize_msgpack($tracer->getTracesAsArray());
        if (false === $messagePack) {
            self::logDebug('Failed to MessagePack-encode trace');
            return '';
        }
        return $messagePack;
    }

    /**
     * {@inheritdoc}
     */
    public function getContentType()
    {
        return 'application/msgpack';
    }
}
