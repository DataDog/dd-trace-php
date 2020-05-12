<?php

namespace DDTrace\Processing;

use DDTrace\Data\Span as DataSpan;
use DDTrace\Tag;

/**
 * A span processor in charge of adding the trace analytics client config metric when appropriate.
 *
 * NOTE: this may be transformer into a filter for consistency with other tracers, but for now we did not implement
 * any filtering functionality so giving it such name as of now might be misleading.
 */
final class TraceAnalyticsProcessor
{
    /**
    * @param array $metrics
    * @param bool|float $value
    */
    public static function normalizeAnalyticsValue(&$metrics, $value)
    {
        if (true === $value) {
            $metrics[Tag::ANALYTICS_KEY] = 1.0;
        } elseif (false === $value) {
            unset($metrics[Tag::ANALYTICS_KEY]);
        } elseif (is_numeric($value) && 0 <= $value && $value <= 1) {
            $metrics[Tag::ANALYTICS_KEY] = (float)$value;
        }
    }
}
