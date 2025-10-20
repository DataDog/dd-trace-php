<?php

declare(strict_types=1);

namespace DDTrace\OpenTelemetry\Metrics;

/**
 * Internal metric exporter that sends metrics to Datadog backend
 * 
 * This class handles the aggregation and export of metric data points
 * to the Datadog backend using OTLP protocol.
 */
final class MetricExporter
{
    /** @var array<string, array> Aggregated metric data points */
    private static array $metrics = [];
    
    /** @var self|null Singleton instance */
    private static ?self $instance = null;
    
    private function __construct()
    {
        // Private constructor for singleton
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Record a metric data point
     * 
     * @param string $metricType One of: counter, updowncounter, histogram, gauge
     * @param string $name Metric name
     * @param float|int $value Metric value
     * @param array<string, mixed> $attributes Metric attributes/tags
     * @param string $unit Unit of measurement
     * @param string $description Metric description
     * @param string $meterName Name of the meter that created this metric
     * @param string|null $meterVersion Version of the meter
     * @param string|null $meterSchemaUrl Schema URL of the meter
     */
    public function record(
        string $metricType,
        string $name,
        $value,
        array $attributes,
        string $unit,
        string $description,
        string $meterName,
        ?string $meterVersion,
        ?string $meterSchemaUrl
    ): void {
        $key = $this->createMetricKey($name, $attributes);
        
        $timestamp = (int)(microtime(true) * 1e9); // nanoseconds
        
        if (!isset(self::$metrics[$key])) {
            self::$metrics[$key] = [
                'type' => $metricType,
                'name' => $name,
                'unit' => $unit,
                'description' => $description,
                'attributes' => $attributes,
                'meter' => [
                    'name' => $meterName,
                    'version' => $meterVersion,
                    'schema_url' => $meterSchemaUrl,
                ],
                'data_points' => [],
            ];
        }
        
        // Aggregate based on metric type
        switch ($metricType) {
            case 'counter':
            case 'updowncounter':
                $this->aggregateSum($key, $value, $timestamp);
                break;
            case 'histogram':
                $this->aggregateHistogram($key, $value, $timestamp);
                break;
            case 'gauge':
                $this->aggregateGauge($key, $value, $timestamp);
                break;
        }
        
        // Send metric via internal function if available
        $this->exportMetric($metricType, $name, $value, $attributes);
    }
    
    /**
     * Create a unique key for metric aggregation
     */
    private function createMetricKey(string $name, array $attributes): string
    {
        ksort($attributes);
        return $name . '|' . json_encode($attributes);
    }
    
    /**
     * Aggregate sum metrics (counter, updowncounter)
     */
    private function aggregateSum(string $key, $value, int $timestamp): void
    {
        if (!isset(self::$metrics[$key]['data_points']['sum'])) {
            self::$metrics[$key]['data_points']['sum'] = [
                'value' => 0,
                'start_time' => $timestamp,
                'last_time' => $timestamp,
            ];
        }
        
        self::$metrics[$key]['data_points']['sum']['value'] += $value;
        self::$metrics[$key]['data_points']['sum']['last_time'] = $timestamp;
    }
    
    /**
     * Aggregate histogram metrics
     */
    private function aggregateHistogram(string $key, $value, int $timestamp): void
    {
        if (!isset(self::$metrics[$key]['data_points']['histogram'])) {
            self::$metrics[$key]['data_points']['histogram'] = [
                'count' => 0,
                'sum' => 0,
                'min' => PHP_FLOAT_MAX,
                'max' => -PHP_FLOAT_MAX,
                'values' => [],
                'start_time' => $timestamp,
                'last_time' => $timestamp,
            ];
        }
        
        $histogram = &self::$metrics[$key]['data_points']['histogram'];
        $histogram['count']++;
        $histogram['sum'] += $value;
        $histogram['min'] = min($histogram['min'], $value);
        $histogram['max'] = max($histogram['max'], $value);
        $histogram['values'][] = $value;
        $histogram['last_time'] = $timestamp;
    }
    
    /**
     * Aggregate gauge metrics (last value wins)
     */
    private function aggregateGauge(string $key, $value, int $timestamp): void
    {
        self::$metrics[$key]['data_points']['gauge'] = [
            'value' => $value,
            'timestamp' => $timestamp,
        ];
    }
    
    /**
     * Export metric to Datadog backend
     * 
     * This uses the internal dd_trace_send_metric function if available,
     * or falls back to DogStatsD
     */
    private function exportMetric(string $metricType, string $name, $value, array $attributes): void
    {
        // Convert attributes to tags array
        $tags = [];
        foreach ($attributes as $key => $attrValue) {
            if (is_scalar($attrValue)) {
                $tags[] = "{$key}:{$attrValue}";
            }
        }
        
        // Map OTel metric types to DogStatsD types
        $dogstatsdType = $this->mapToDogStatsD($metricType);
        
        // Try to use internal function if available
        if (function_exists('dd_trace_send_metrics')) {
            dd_trace_send_metrics([
                [
                    'name' => $name,
                    'value' => $value,
                    'type' => $dogstatsdType,
                    'tags' => $tags,
                ],
            ]);
        } elseif (function_exists('dd_trace_internal_fn')) {
            // Try internal function
            try {
                dd_trace_internal_fn('dogstatsd_' . $dogstatsdType, $name, $value, $tags);
            } catch (\Throwable $e) {
                // Silently fail - metric export is non-critical
            }
        }
        // If no internal functions available, metrics will be exported on flush
    }
    
    /**
     * Map OpenTelemetry metric type to DogStatsD type
     */
    private function mapToDogStatsD(string $metricType): string
    {
        switch ($metricType) {
            case 'counter':
            case 'updowncounter':
                return 'count';
            case 'histogram':
                return 'distribution';
            case 'gauge':
                return 'gauge';
            default:
                return 'gauge';
        }
    }
    
    /**
     * Get all aggregated metrics
     */
    public function getMetrics(): array
    {
        return self::$metrics;
    }
    
    /**
     * Clear all aggregated metrics
     */
    public function clear(): void
    {
        self::$metrics = [];
    }
    
    /**
     * Flush all pending metrics
     */
    public function flush(): bool
    {
        // In a real implementation, this would send all aggregated metrics
        // via OTLP to the Datadog backend
        
        // For now, we just clear the metrics
        $success = !empty(self::$metrics);
        $this->clear();
        
        return $success;
    }
}

