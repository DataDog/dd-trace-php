<?php

declare(strict_types=1);

namespace OpenTelemetry\API\Metrics;

use DDTrace\OpenTelemetry\Metrics\Instruments\NoopCounter;
use DDTrace\OpenTelemetry\Metrics\Instruments\NoopUpDownCounter;
use DDTrace\OpenTelemetry\Metrics\Instruments\NoopHistogram;
use DDTrace\OpenTelemetry\Metrics\Instruments\NoopAsynchronousCounter;
use DDTrace\OpenTelemetry\Metrics\Instruments\NoopAsynchronousUpDownCounter;
use DDTrace\OpenTelemetry\Metrics\Instruments\NoopAsynchronousGauge;

/**
 * No-op Meter implementation
 * 
 * Returns no-op instruments for all create methods
 */
final class NoopMeter implements MeterInterface
{
    public function createCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array $advisory = []
    ): CounterInterface {
        return new NoopCounter();
    }
    
    public function createObservableCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        $callbacks = [],
        array $advisory = []
    ): ObservableCounterInterface {
        return new NoopAsynchronousCounter();
    }
    
    public function createUpDownCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array $advisory = []
    ): UpDownCounterInterface {
        return new NoopUpDownCounter();
    }
    
    public function createObservableUpDownCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        $callbacks = [],
        array $advisory = []
    ): ObservableUpDownCounterInterface {
        return new NoopAsynchronousUpDownCounter();
    }
    
    public function createHistogram(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array $advisory = []
    ): HistogramInterface {
        return new NoopHistogram();
    }
    
    public function createObservableGauge(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        $callbacks = [],
        array $advisory = []
    ): ObservableGaugeInterface {
        return new NoopAsynchronousGauge();
    }
}

