<?php

declare(strict_types=1);

namespace DDTrace\OpenTelemetry\Metrics\Instruments;

use OpenTelemetry\API\Metrics\CounterInterface;

/**
 * No-op Counter implementation
 * 
 * This is returned when an instrument is created with an invalid name
 * or configuration. All operations are no-ops.
 */
final class NoopCounter implements CounterInterface
{
    /**
     * No-op add operation
     * 
     * @param int|float $amount
     * @param iterable $attributes
     * @param mixed $context
     * @return void
     */
    public function add($amount, iterable $attributes = [], $context = null): void
    {
        // No-op
    }
}

