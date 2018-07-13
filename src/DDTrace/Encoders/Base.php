<?php

namespace DDTrace\Encoders;

use DDTrace\Encoder;
use DDTrace\Span;

class Base implements Encoder
{
    /**
     * @param Span $span
     * @return array
     */
    protected function spanToArray(Span $span)
    {
        $arraySpan = [
            'trace_id_hex' => '-',
            'span_id_hex' => '-',
            'name' => $span->getOperationName(),
            'resource' => $span->getResource(),
            'service' => $span->getService(),
            'start_micro' => '-',
            'error' => $span->hasError() ? 1 : 0,
        ];

        if ($span->getType() !== null) {
            $arraySpan['type'] = $span->getType();
        }

        if ($span->isFinished()) {
            $arraySpan['duration_micro'] = '-';
        }

        if ($span->getParentId() !== null) {
            $arraySpan['parent_id_hex'] = '-';
        }

        if (!empty($span->getAllTags())) {
            $arraySpan['meta'] = $span->getAllTags();
        }

        return $arraySpan;
    }
}
