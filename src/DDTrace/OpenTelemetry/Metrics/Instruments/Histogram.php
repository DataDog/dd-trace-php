<?php

declare(strict_types=1);

namespace DDTrace\OpenTelemetry\Metrics\Instruments;

use OpenTelemetry\API\Metrics\HistogramInterface;
use DDTrace\OpenTelemetry\Metrics\MetricExporter;

/**
 * Histogram instrument - statistical distribution of values
 * 
 * Histograms are used to record the distribution of values, such as:
 * - Request duration
 * - Response size
 * - Database query time
 */
final class Histogram implements HistogramInterface
{
    private string $name;
    private string $unit;
    private string $description;
    private ?array $boundaries;
    private string $meterName;
    private ?string $meterVersion;
    private ?string $meterSchemaUrl;
    
    public function __construct(
        string $name,
        string $unit,
        string $description,
        ?array $boundaries,
        string $meterName,
        ?string $meterVersion,
        ?string $meterSchemaUrl
    ) {
        $this->name = $name;
        $this->unit = $unit;
        $this->description = $description;
        $this->boundaries = $boundaries;
        $this->meterName = $meterName;
        $this->meterVersion = $meterVersion;
        $this->meterSchemaUrl = $meterSchemaUrl;
    }
    
    /**
     * Record a value in the histogram
     * 
     * @param int|float $amount The value to record (must be non-negative)
     * @param iterable $attributes Optional attributes to associate with this measurement
     * @param mixed $context Optional context (not used in DD implementation)
     * @return void
     */
    public function record($amount, iterable $attributes = [], $context = null): void
    {
        // Validate that value is non-negative
        if ($amount < 0) {
            error_log(
                "[DDTrace] OpenTelemetry Metrics: Histogram '{$this->name}' received negative value: {$amount}. " .
                "Histograms should only record non-negative values. Value will not be recorded."
            );
            return;
        }
        
        // Convert attributes to array
        $attributesArray = $this->convertAttributes($attributes);
        
        // Record the metric
        MetricExporter::getInstance()->record(
            'histogram',
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
    
    /**
     * Get the configured bucket boundaries
     * 
     * @return array|null
     */
    public function getBoundaries(): ?array
    {
        return $this->boundaries;
    }
}

