<?php

declare(strict_types=1);

namespace OpenTelemetry\API\Metrics;

use OpenTelemetry\SDK\Metrics\MeterProvider;

/**
 * Global MeterProvider accessor for OpenTelemetry Metrics API
 * 
 * This class provides a way to get and set the global default MeterProvider.
 * The Datadog implementation intercepts this to ensure metrics are sent to Datadog.
 */
final class GlobalMeterProvider
{
    /** @var MeterProviderInterface|null */
    private static ?MeterProviderInterface $instance = null;
    
    /** @var bool */
    private static bool $initialized = false;
    
    /**
     * Get the global MeterProvider instance
     * 
     * @return MeterProviderInterface
     */
    public static function get(): MeterProviderInterface
    {
        if (!self::$initialized) {
            self::$instance = new MeterProvider();
            self::$initialized = true;
        }
        
        return self::$instance ?? new NoopMeterProvider();
    }
    
    /**
     * Set the global MeterProvider instance
     * 
     * Note: In Datadog's implementation, this should always ensure the
     * Datadog MeterProvider is used to avoid duplicate metrics.
     * 
     * @param MeterProviderInterface $meterProvider
     * @return void
     */
    public static function set(MeterProviderInterface $meterProvider): void
    {
        self::$instance = $meterProvider;
        self::$initialized = true;
    }
    
    /**
     * Reset the global MeterProvider (mainly for testing)
     * 
     * @return void
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$initialized = false;
    }
}

