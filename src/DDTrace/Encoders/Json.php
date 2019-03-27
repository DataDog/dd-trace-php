<?php

namespace DDTrace\Encoders;

use DDTrace\Contracts\Span;
use DDTrace\Encoder;
use DDTrace\Log\LoggingTrait;

final class Json implements Encoder
{
    use LoggingTrait;

    /**
     * {@inheritdoc}
     */
    public function encodeTraces(array $traces)
    {
        return '[' . implode(',', array_map(function ($trace) {
            return '[' . implode(',', array_filter(array_map(function ($span) {
                return $this->encodeSpan($span);
            }, $trace))) . ']';
        }, $traces))  . ']';
    }

    /**
     * {@inheritdoc}
     */
    public function getContentType()
    {
        return 'application/json';
    }

    /**
     * @param Span $span
     * @return string
     */
    private function encodeSpan(Span $span)
    {
        $json = json_encode(SpanEncoder::encode($span));
        if (false === $json) {
            self::logDebug('Failed to json-encode span: ' . json_last_error_msg());
            return '';
        }
        return $json;
    }
}
