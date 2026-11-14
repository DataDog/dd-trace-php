<?php

namespace DDTrace\Processing;

/**
 * @deprecated App Analytics is deprecated and no longer has any effect.
 */
final class TraceAnalyticsProcessor
{
    /**
     * @deprecated App Analytics is deprecated. This is now a no-op and does not
     *             modify $metrics or emit the _dd1.sr.eausr metric.
     *
     * @param array $metrics
     * @param bool|float $value
     */
    public static function normalizeAnalyticsValue(&$metrics, $value)
    {
    }
}
