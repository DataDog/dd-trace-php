<?php

namespace DDTrace\Encoders;

use DDTrace\Contracts\Tracer;
use DDTrace\Encoder;
use DDTrace\Log\LoggingTrait;

final class MessagePack implements Encoder
{
    use LoggingTrait;

    /**
     * {@inheritdoc}
     */
    public function encodeTraces(Tracer $tracer)
    {
        $traces = $tracer->getTracesAsArray();
        error_log('Traces: ' . var_export($traces, 1));
        $messagePack = dd_trace_serialize_msgpack($traces);
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
