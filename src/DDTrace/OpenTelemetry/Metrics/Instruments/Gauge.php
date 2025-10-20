<?php

declare(strict_types=1);

namespace DDTrace\OpenTelemetry\Metrics\Instruments;

use DDTrace\OpenTelemetry\Metrics\MetricExporter;

/**
 * Gauge instrument - instantaneous measurement
 * 
 * Gauges are used to record values that represent a point-in-time measurement, such as:
 * - Temperature
 * - Current memory usage
 * - Queue length at a specific time
 * 
 * Note: Synchronous Gauge is not part of the stable OTel Metrics API spec yet
 */
final class Gauge
{
    private string $name;
    private string $unit;
    private string $description;
    private string $meterName;
    private ?string $meterVersion;
    private ?string $meterSchemaUrl;
    
    public function __construct(
        string $name,
        string $unit,
        string $description,
        string $meterName,
        ?string $meterVersion,
        ?string $meterSchemaUrl
    ) {
        $this->name = $name;
        $this->unit = $unit;
        $this->description = $description;
        $this->meterName = $meterName;
        $this->meterVersion = $meterVersion;
        $this->meterSchemaUrl = $meterSchemaUrl;
    }
    
    /**
     * Record a gauge value
     * 
     * @param int|float $amount The current value to record
     * @param iterable $attributes Optional attributes to associate with this measurement
     * @param mixed $context Optional context (not used in DD implementation)
     * @return void
     */
    public function record($amount, iterable $attributes = [], $context = null): void
    {
        // Convert attributes to array
        $attributesArray = $this->convertAttributes($attributes);
        
        // Record the metric
        MetricExporter::getInstance()->record(
            'gauge',
            $this->name,
            $amount,
            $attributesArray,
            $this->unit,
            $this->description,
            $this->meterName,
            $this->meterVersion,
            $this->meterSchemaUrl
        );
    }
    
    /**
     * Convert attributes to array format
     */
    private function convertAttributes(iterable $attributes): array
    {
        $result = [];
        
        foreach ($attributes as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $result[$key] = $value;
            } elseif (is_array($value)) {
                $result[$key] = json_encode($value);
            } elseif (is_object($value) && method_exists($value, '__toString')) {
                $result[$key] = (string)$value;
            }
        }
        
        return $result;
    }
}

