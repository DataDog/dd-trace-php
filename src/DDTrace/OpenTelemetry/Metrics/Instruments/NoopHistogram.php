<?php

declare(strict_types=1);

namespace DDTrace\OpenTelemetry\Metrics\Instruments;

use OpenTelemetry\API\Metrics\HistogramInterface;

/**
 * No-op Histogram implementation
 * 
 * This is returned when an instrument is created with an invalid name
 * or configuration. All operations are no-ops.
 */
final class NoopHistogram implements HistogramInterface
{
    /**
     * No-op record operation
     * 
     * @param int|float $amount
     * @param iterable $attributes
     * @param mixed $context
     * @return void
     */
    public function record($amount, iterable $attributes = [], $context = null): void
    {
        // No-op
    }
}

