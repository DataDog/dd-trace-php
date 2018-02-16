<?php

namespace DDTrace\Propagators;

use DDTrace\Propagator;
use DDTrace\SpanContext;

final class TextMap implements Propagator
{
    const DEFAULT_BAGGAGE_HEADER_PREFIX = 'ot-baggage-';
    const DEFAULT_TRACE_ID_HEADER = 'x-datadog-trace-id';
    const DEFAULT_PARENT_ID_HEADER = 'x-datadog-parent-id';

    /**
     * {@inheritdoc}
     */
    public function inject(SpanContext $spanContext, &$carrier)
    {
        $carrier[self::DEFAULT_TRACE_ID_HEADER] = $spanContext->getTraceId();
        $carrier[self::DEFAULT_PARENT_ID_HEADER] = $spanContext->getSpanId();

        foreach ($spanContext as $key => $value) {
            $carrier[self::DEFAULT_BAGGAGE_HEADER_PREFIX . $key] = $value;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function extract($carrier)
    {
        $traceId = null;
        $spanId = null;
        $baggageItems = [];

        foreach ($carrier as $key => $value) {
            if ($key === self::DEFAULT_TRACE_ID_HEADER) {
                $traceId = $value;
            } elseif ($key === self::DEFAULT_PARENT_ID_HEADER) {
                $spanId = $value;
            } elseif (strpos($key, self::DEFAULT_BAGGAGE_HEADER_PREFIX) === 0) {
                $baggageItems[substr($key, strlen(self::DEFAULT_BAGGAGE_HEADER_PREFIX))] = $value;
            }

            continue;
        }

        if ($traceId === null && $spanId === null) {
            return null;
        }

        return new SpanContext($traceId, $spanId, null, $baggageItems);
    }
}
