<?php

declare(strict_types=1);

namespace DDTrace\OpenTelemetry\Metrics\Instruments;

use OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;
use DDTrace\OpenTelemetry\Metrics\MetricExporter;

/**
 * Asynchronous UpDownCounter - value that can increase or decrease, observed via callbacks
 * 
 * Asynchronous up-down counters are used for values that can be sampled at any time
 * and can both increase and decrease, such as:
 * - Current memory usage
 * - Active request count
 */
final class AsynchronousUpDownCounter implements ObservableUpDownCounterInterface
{
    private string $name;
    private string $unit;
    private string $description;
    private string $meterName;
    private ?string $meterVersion;
    private ?string $meterSchemaUrl;
    
    /** @var array<callable> */
    private array $callbacks = [];
    
    public function __construct(
        string $name,
        string $unit,
        string $description,
        array $callbacks,
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
        $this->callbacks = $callbacks;
    }
    
    /**
     * Add callbacks to this instrument
     * 
     * @param array<callable> $callbacks
     * @return void
     */
    public function addCallbacks(array $callbacks): void
    {
        $this->callbacks = array_merge($this->callbacks, $callbacks);
    }
    
    /**
     * Observe values by executing callbacks
     * 
     * This method is called internally during metric collection
     * 
     * @return void
     */
    public function observe(): void
    {
        foreach ($this->callbacks as $callback) {
            try {
                // Create an observer that will record the observations
                $observer = new class($this->name, $this->unit, $this->description, $this->meterName, $this->meterVersion, $this->meterSchemaUrl) {
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
                    
                    public function observe($amount, iterable $attributes = []): void
                    {
                        $attributesArray = [];
                        foreach ($attributes as $key => $value) {
                            if (is_scalar($value) || $value === null) {
                                $attributesArray[$key] = $value;
                            }
                        }
                        
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
                };
                
                // Call the callback with the observer
                $callback($observer);
            } catch (\Throwable $e) {
                error_log(
                    "[DDTrace] OpenTelemetry Metrics: Error executing callback for " .
                    "asynchronous up-down counter '{$this->name}': {$e->getMessage()}"
                );
            }
        }
    }
}

