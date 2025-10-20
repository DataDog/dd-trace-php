<?php

/**
 * Bridge file for OpenTelemetry Metrics API integration
 * 
 * This file lists the Datadog implementation files for the OpenTelemetry Metrics API.
 * These files will be autoloaded when dd-trace is enabled.
 */

return [
    // Supporting utilities
    __DIR__ . '/../DDTrace/OpenTelemetry/Metrics/InstrumentValidator.php',
    __DIR__ . '/../DDTrace/OpenTelemetry/Metrics/MetricExporter.php',
    
    // Synchronous instruments
    __DIR__ . '/../DDTrace/OpenTelemetry/Metrics/Instruments/Counter.php',
    __DIR__ . '/../DDTrace/OpenTelemetry/Metrics/Instruments/UpDownCounter.php',
    __DIR__ . '/../DDTrace/OpenTelemetry/Metrics/Instruments/Histogram.php',
    __DIR__ . '/../DDTrace/OpenTelemetry/Metrics/Instruments/Gauge.php',
    
    // Asynchronous instruments
    __DIR__ . '/../DDTrace/OpenTelemetry/Metrics/Instruments/AsynchronousCounter.php',
    __DIR__ . '/../DDTrace/OpenTelemetry/Metrics/Instruments/AsynchronousUpDownCounter.php',
    __DIR__ . '/../DDTrace/OpenTelemetry/Metrics/Instruments/AsynchronousGauge.php',
    
    // No-op implementations
    __DIR__ . '/../DDTrace/OpenTelemetry/Metrics/Instruments/NoopCounter.php',
    __DIR__ . '/../DDTrace/OpenTelemetry/Metrics/Instruments/NoopUpDownCounter.php',
    __DIR__ . '/../DDTrace/OpenTelemetry/Metrics/Instruments/NoopHistogram.php',
    __DIR__ . '/../DDTrace/OpenTelemetry/Metrics/Instruments/NoopAsynchronousCounter.php',
    __DIR__ . '/../DDTrace/OpenTelemetry/Metrics/Instruments/NoopAsynchronousUpDownCounter.php',
    __DIR__ . '/../DDTrace/OpenTelemetry/Metrics/Instruments/NoopAsynchronousGauge.php',
    
    // Core components (loaded last to ensure dependencies are available)
    __DIR__ . '/../DDTrace/OpenTelemetry/Metrics/NoopMeter.php',
    __DIR__ . '/../DDTrace/OpenTelemetry/Metrics/NoopMeterProvider.php',
    __DIR__ . '/../DDTrace/OpenTelemetry/Metrics/Meter.php',
    __DIR__ . '/../DDTrace/OpenTelemetry/Metrics/MeterProvider.php',
    __DIR__ . '/../DDTrace/OpenTelemetry/Metrics/GlobalMeterProvider.php',
];

