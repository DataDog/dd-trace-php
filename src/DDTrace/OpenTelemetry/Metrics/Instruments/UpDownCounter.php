<?php

declare(strict_types=1);

namespace DDTrace\OpenTelemetry\Metrics\Instruments;

use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use DDTrace\OpenTelemetry\Metrics\MetricExporter;

/**
 * UpDownCounter instrument - value that can increase or decrease
 * 
 * UpDownCounters are used for values that can go up or down, such as:
 * - Active connections
 * - Queue size
 * - Items in a cache
 */
final class UpDownCounter implements UpDownCounterInterface
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
     * Add a value to the up-down counter (can be negative)
     * 
     * @param int|float $amount The amount to add (can be positive or negative)
     * @param iterable $attributes Optional attributes to associate with this measurement
     * @param mixed $context Optional context (not used in DD implementation)
     * @return void
     */
    public function add($amount, iterable $attributes = [], $context = null): void
    {
        // Convert attributes to array
        $attributesArray = $this->convertAttributes($attributes);
        
        // Record the metric
        MetricExporter::getInstance()->record(
            'updowncounter',
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

