<?php

declare(strict_types=1);

namespace DDTrace\OpenTelemetry\Metrics\Instruments;

use OpenTelemetry\API\Metrics\CounterInterface;
use DDTrace\OpenTelemetry\Metrics\MetricExporter;

/**
 * Counter instrument - monotonically increasing value
 * 
 * Counters are used for values that only increase over time, such as:
 * - Request counts
 * - Bytes sent
 * - Tasks completed
 */
final class Counter implements CounterInterface
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
     * Add a value to the counter
     * 
     * @param int|float $amount The amount to increment (must be non-negative)
     * @param iterable $attributes Optional attributes to associate with this measurement
     * @param mixed $context Optional context (not used in DD implementation)
     * @return void
     */
    public function add($amount, iterable $attributes = [], $context = null): void
    {
        // Validate that increment is non-negative
        if ($amount < 0) {
            error_log(
                "[DDTrace] OpenTelemetry Metrics: Counter '{$this->name}' received negative value: {$amount}. " .
                "Counters must only increment. Value will not be recorded."
            );
            return;
        }
        
        // Convert attributes to array
        $attributesArray = $this->convertAttributes($attributes);
        
        // Record the metric
        MetricExporter::getInstance()->record(
            'counter',
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

