<?php

namespace DDTrace\Encoders;

use DDTrace\Encoder;
use DDTrace\Span;

final class Json implements Encoder
{
    /**
     * {@inheritdoc}
     */
    public function encodeTraces(array $traces)
    {
        return '[' . implode(',', array_map(function ($trace) {
            return '[' . implode(',', array_map(function ($span) {
                return Json::encodeSpan($span);
            }, $trace)) . ']';
        }, $traces))  . ']';
    }

    private static function encodeSpan(Span $span)
    {
        return str_replace([
            '"start_micro":0',
            '"duration_micro":0',
        ], [
            '"start":' . $span->getStartTime() . '000',
            '"duration":' . $span->getDuration() . '000',
        ], json_encode(Json::spanToArray($span)));
    }

    public function getContentType()
    {
        return 'application/json';
    }

    /**
     * @param Span $span
     * @return array
     */
    private static function spanToArray(Span $span)
    {
        $arraySpan = [
            'trace_id' => $span->getTraceId(),
            'span_id' => $span->getSpanId(),
            'name' => $span->getOperationName(),
            'resource' => $span->getResource(),
            'service' => $span->getService(),
            'start_micro' => 0,
            'error' => $span->hasError() ? 1 : 0,
        ];

        if ($span->getType() !== null) {
            $arraySpan['type'] = $span->getType();
        }

        if ($span->isFinished()) {
            $arraySpan['duration_micro'] = 0;
        }

        if ($span->getParentId() !== null) {
            $arraySpan['parent_id'] = $span->getParentId();
        }

        if (!empty($span->getAllTags())) {
            $arraySpan['meta'] = $span->getAllTags();
        }

        return $arraySpan;
    }
}
