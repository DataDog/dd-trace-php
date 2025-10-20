<?php

declare(strict_types=1);

namespace OpenTelemetry\SDK\Metrics;

use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;

/**
 * Datadog implementation of OpenTelemetry MeterProvider
 * 
 * This class intercepts calls to the OpenTelemetry Metrics API and forwards
 * metrics to the Datadog backend via OTLP.
 */
final class MeterProvider implements MeterProviderInterface
{
    /** @var array<string, MeterInterface> */
    private array $meters = [];
    
    private ResourceInfo $resource;
    
    public function __construct(?ResourceInfo $resource = null)
    {
        $this->resource = $resource ?? ResourceInfo::emptyResource();
    }
    
    /**
     * Get or create a Meter instance
     * 
     * @param string $name Required name for the meter
     * @param string|null $version Optional version
     * @param string|null $schemaUrl Optional schema URL
     * @param AttributesInterface|null $attributes Optional attributes
     * @return MeterInterface
     */
    public function getMeter(
        string $name,
        ?string $version = null,
        ?string $schemaUrl = null,
        ?AttributesInterface $attributes = null
    ): MeterInterface {
        // Create a unique key for this meter configuration
        $key = $this->createMeterKey($name, $version, $schemaUrl, $attributes);
        
        // Return existing meter if already created
        if (isset($this->meters[$key])) {
            return $this->meters[$key];
        }
        
        // Create new meter
        $meter = new Meter(
            $name,
            $version,
            $schemaUrl,
            $attributes,
            $this->resource
        );
        
        $this->meters[$key] = $meter;
        
        return $meter;
    }
    
    /**
     * Create a unique key for meter identification
     */
    private function createMeterKey(
        string $name,
        ?string $version,
        ?string $schemaUrl,
        ?AttributesInterface $attributes
    ): string {
        $parts = [$name];
        
        if ($version !== null) {
            $parts[] = $version;
        }
        
        if ($schemaUrl !== null) {
            $parts[] = $schemaUrl;
        }
        
        if ($attributes !== null) {
            $parts[] = json_encode($attributes->toArray());
        }
        
        return implode('|', $parts);
    }
    
    /**
     * Shutdown the meter provider and flush any pending metrics
     */
    public function shutdown(): bool
    {
        // Flush any pending metrics
        foreach ($this->meters as $meter) {
            if (method_exists($meter, 'shutdown')) {
                $meter->shutdown();
            }
        }
        
        return true;
    }
    
    /**
     * Force flush any pending metrics
     */
    public function forceFlush(): bool
    {
        foreach ($this->meters as $meter) {
            if (method_exists($meter, 'forceFlush')) {
                $meter->forceFlush();
            }
        }
        
        return true;
    }
}

