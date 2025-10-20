<?php

declare(strict_types=1);

namespace DDTrace\OpenTelemetry\Metrics\Instruments;

use OpenTelemetry\API\Metrics\ObservableCounterInterface;

/**
 * No-op Asynchronous Counter implementation
 * 
 * This is returned when an instrument is created with an invalid name
 * or configuration. All operations are no-ops.
 */
final class NoopAsynchronousCounter implements ObservableCounterInterface
{
    // No public API for asynchronous instruments
}

