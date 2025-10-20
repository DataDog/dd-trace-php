<?php

declare(strict_types=1);

namespace OpenTelemetry\SDK\Metrics;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;
use OpenTelemetry\API\Metrics\ObservableCounterInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use DDTrace\OpenTelemetry\Metrics\InstrumentValidator;
use DDTrace\OpenTelemetry\Metrics\Instruments\Counter;
use DDTrace\OpenTelemetry\Metrics\Instruments\AsynchronousCounter;
use DDTrace\OpenTelemetry\Metrics\Instruments\UpDownCounter;
use DDTrace\OpenTelemetry\Metrics\Instruments\AsynchronousUpDownCounter;
use DDTrace\OpenTelemetry\Metrics\Instruments\Histogram;
use DDTrace\OpenTelemetry\Metrics\Instruments\Gauge;
use DDTrace\OpenTelemetry\Metrics\Instruments\AsynchronousGauge;
use DDTrace\OpenTelemetry\Metrics\Instruments\NoopCounter;
use DDTrace\OpenTelemetry\Metrics\Instruments\NoopHistogram;
use DDTrace\OpenTelemetry\Metrics\Instruments\NoopUpDownCounter;
use DDTrace\OpenTelemetry\Metrics\Instruments\NoopAsynchronousCounter;
use DDTrace\OpenTelemetry\Metrics\Instruments\NoopAsynchronousGauge;
use DDTrace\OpenTelemetry\Metrics\Instruments\NoopAsynchronousUpDownCounter;

/**
 * Datadog implementation of OpenTelemetry Meter
 * 
 * The Meter is responsible for creating instruments that record measurements.
 */
final class Meter implements MeterInterface
{
    private string $name;
    private ?string $version;
    private ?string $schemaUrl;
    private ?AttributesInterface $attributes;
    private ResourceInfo $resource;
    
    /** @var array<string, object> Registered instruments */
    private array $instruments = [];
    
    public function __construct(
        string $name,
        ?string $version = null,
        ?string $schemaUrl = null,
        ?AttributesInterface $attributes = null,
        ?ResourceInfo $resource = null
    ) {
        $this->name = $name;
        $this->version = $version;
        $this->schemaUrl = $schemaUrl;
        $this->attributes = $attributes;
        $this->resource = $resource ?? ResourceInfo::emptyResource();
    }
    
    /**
     * Create a Counter instrument
     * 
     * @param string $name Required name for the instrument
     * @param string|null $unit Optional unit of measure
     * @param string|null $description Optional description
     * @param array $advisory Optional advisory parameters
     * @return CounterInterface
     */
    public function createCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array $advisory = []
    ): CounterInterface {
        // Validate instrument name
        if (!InstrumentValidator::isValidInstrumentName($name)) {
            InstrumentValidator::logInvalidNameWarning($name);
            return new NoopCounter();
        }
        
        $unit = InstrumentValidator::normalizeUnit($unit);
        $description = InstrumentValidator::normalizeDescription($description);
        
        // Check if instrument already exists
        $key = $this->createInstrumentKey('counter', $name, $unit, $description);
        
        if (isset($this->instruments[$key])) {
            $existing = $this->instruments[$key];
            
            // Check if advisory parameters match
            if ($this->advisoryParametersMatch($existing, $advisory)) {
                return $existing;
            } else {
                InstrumentValidator::logInstrumentConflictWarning($name, 'advisory parameters');
                return $existing;
            }
        }
        
        // Create new counter
        $counter = new Counter(
            $name,
            $unit,
            $description,
            $this->name,
            $this->version,
            $this->schemaUrl
        );
        
        $this->instruments[$key] = $counter;
        
        return $counter;
    }
    
    /**
     * Create an Asynchronous Counter instrument
     * 
     * @param string $name Required name for the instrument
     * @param string|null $unit Optional unit of measure
     * @param string|null $description Optional description
     * @param callable|array $callbacks Optional callback function(s)
     * @param array $advisory Optional advisory parameters
     * @return ObservableCounterInterface
     */
    public function createObservableCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        $callbacks = [],
        array $advisory = []
    ): ObservableCounterInterface {
        // Validate instrument name
        if (!InstrumentValidator::isValidInstrumentName($name)) {
            InstrumentValidator::logInvalidNameWarning($name);
            return new NoopAsynchronousCounter();
        }
        
        $unit = InstrumentValidator::normalizeUnit($unit);
        $description = InstrumentValidator::normalizeDescription($description);
        
        // Normalize callbacks to array
        if (!is_array($callbacks)) {
            $callbacks = $callbacks !== null ? [$callbacks] : [];
        }
        
        // Check if instrument already exists
        $key = $this->createInstrumentKey('async_counter', $name, $unit, $description);
        
        if (isset($this->instruments[$key])) {
            $existing = $this->instruments[$key];
            
            // Add new callbacks to existing instrument
            if (!empty($callbacks) && method_exists($existing, 'addCallbacks')) {
                $existing->addCallbacks($callbacks);
            }
            
            return $existing;
        }
        
        // Create new asynchronous counter
        $asyncCounter = new AsynchronousCounter(
            $name,
            $unit,
            $description,
            $callbacks,
            $this->name,
            $this->version,
            $this->schemaUrl
        );
        
        $this->instruments[$key] = $asyncCounter;
        
        return $asyncCounter;
    }
    
    /**
     * Create an UpDownCounter instrument
     * 
     * @param string $name Required name for the instrument
     * @param string|null $unit Optional unit of measure
     * @param string|null $description Optional description
     * @param array $advisory Optional advisory parameters
     * @return UpDownCounterInterface
     */
    public function createUpDownCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array $advisory = []
    ): UpDownCounterInterface {
        // Validate instrument name
        if (!InstrumentValidator::isValidInstrumentName($name)) {
            InstrumentValidator::logInvalidNameWarning($name);
            return new NoopUpDownCounter();
        }
        
        $unit = InstrumentValidator::normalizeUnit($unit);
        $description = InstrumentValidator::normalizeDescription($description);
        
        // Check if instrument already exists
        $key = $this->createInstrumentKey('updowncounter', $name, $unit, $description);
        
        if (isset($this->instruments[$key])) {
            $existing = $this->instruments[$key];
            
            if ($this->advisoryParametersMatch($existing, $advisory)) {
                return $existing;
            } else {
                InstrumentValidator::logInstrumentConflictWarning($name, 'advisory parameters');
                return $existing;
            }
        }
        
        // Create new updowncounter
        $upDownCounter = new UpDownCounter(
            $name,
            $unit,
            $description,
            $this->name,
            $this->version,
            $this->schemaUrl
        );
        
        $this->instruments[$key] = $upDownCounter;
        
        return $upDownCounter;
    }
    
    /**
     * Create an Asynchronous UpDownCounter instrument
     * 
     * @param string $name Required name for the instrument
     * @param string|null $unit Optional unit of measure
     * @param string|null $description Optional description
     * @param callable|array $callbacks Optional callback function(s)
     * @param array $advisory Optional advisory parameters
     * @return ObservableUpDownCounterInterface
     */
    public function createObservableUpDownCounter(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        $callbacks = [],
        array $advisory = []
    ): ObservableUpDownCounterInterface {
        // Validate instrument name
        if (!InstrumentValidator::isValidInstrumentName($name)) {
            InstrumentValidator::logInvalidNameWarning($name);
            return new NoopAsynchronousUpDownCounter();
        }
        
        $unit = InstrumentValidator::normalizeUnit($unit);
        $description = InstrumentValidator::normalizeDescription($description);
        
        // Normalize callbacks to array
        if (!is_array($callbacks)) {
            $callbacks = $callbacks !== null ? [$callbacks] : [];
        }
        
        // Check if instrument already exists
        $key = $this->createInstrumentKey('async_updowncounter', $name, $unit, $description);
        
        if (isset($this->instruments[$key])) {
            $existing = $this->instruments[$key];
            
            if (!empty($callbacks) && method_exists($existing, 'addCallbacks')) {
                $existing->addCallbacks($callbacks);
            }
            
            return $existing;
        }
        
        // Create new asynchronous updowncounter
        $asyncUpDownCounter = new AsynchronousUpDownCounter(
            $name,
            $unit,
            $description,
            $callbacks,
            $this->name,
            $this->version,
            $this->schemaUrl
        );
        
        $this->instruments[$key] = $asyncUpDownCounter;
        
        return $asyncUpDownCounter;
    }
    
    /**
     * Create a Histogram instrument
     * 
     * @param string $name Required name for the instrument
     * @param string|null $unit Optional unit of measure
     * @param string|null $description Optional description
     * @param array $advisory Optional advisory parameters (e.g., ExplicitBucketBoundaries)
     * @return HistogramInterface
     */
    public function createHistogram(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array $advisory = []
    ): HistogramInterface {
        // Validate instrument name
        if (!InstrumentValidator::isValidInstrumentName($name)) {
            InstrumentValidator::logInvalidNameWarning($name);
            return new NoopHistogram();
        }
        
        $unit = InstrumentValidator::normalizeUnit($unit);
        $description = InstrumentValidator::normalizeDescription($description);
        
        // Validate advisory parameters
        $boundaries = $advisory['ExplicitBucketBoundaries'] ?? null;
        if ($boundaries !== null && !InstrumentValidator::isValidExplicitBucketBoundaries($boundaries)) {
            InstrumentValidator::logInvalidAdvisoryParameterWarning('ExplicitBucketBoundaries');
            $boundaries = null;
        }
        
        // Check if instrument already exists
        $key = $this->createInstrumentKey('histogram', $name, $unit, $description);
        
        if (isset($this->instruments[$key])) {
            $existing = $this->instruments[$key];
            
            if ($this->advisoryParametersMatch($existing, $advisory)) {
                return $existing;
            } else {
                InstrumentValidator::logInstrumentConflictWarning($name, 'advisory parameters');
                return $existing;
            }
        }
        
        // Create new histogram
        $histogram = new Histogram(
            $name,
            $unit,
            $description,
            $boundaries,
            $this->name,
            $this->version,
            $this->schemaUrl
        );
        
        $this->instruments[$key] = $histogram;
        
        return $histogram;
    }
    
    /**
     * Create a Gauge instrument
     * 
     * Note: Synchronous Gauge is not part of the stable OTel spec yet,
     * but we implement it for completeness
     * 
     * @param string $name Required name for the instrument
     * @param string|null $unit Optional unit of measure
     * @param string|null $description Optional description
     * @param array $advisory Optional advisory parameters
     * @return object Gauge-like interface
     */
    public function createGauge(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        array $advisory = []
    ): object {
        // Validate instrument name
        if (!InstrumentValidator::isValidInstrumentName($name)) {
            InstrumentValidator::logInvalidNameWarning($name);
            return new NoopHistogram(); // Use noop histogram as placeholder
        }
        
        $unit = InstrumentValidator::normalizeUnit($unit);
        $description = InstrumentValidator::normalizeDescription($description);
        
        // Check if instrument already exists
        $key = $this->createInstrumentKey('gauge', $name, $unit, $description);
        
        if (isset($this->instruments[$key])) {
            return $this->instruments[$key];
        }
        
        // Create new gauge
        $gauge = new Gauge(
            $name,
            $unit,
            $description,
            $this->name,
            $this->version,
            $this->schemaUrl
        );
        
        $this->instruments[$key] = $gauge;
        
        return $gauge;
    }
    
    /**
     * Create an Asynchronous Gauge instrument
     * 
     * @param string $name Required name for the instrument
     * @param string|null $unit Optional unit of measure
     * @param string|null $description Optional description
     * @param callable|array $callbacks Optional callback function(s)
     * @param array $advisory Optional advisory parameters
     * @return ObservableGaugeInterface
     */
    public function createObservableGauge(
        string $name,
        ?string $unit = null,
        ?string $description = null,
        $callbacks = [],
        array $advisory = []
    ): ObservableGaugeInterface {
        // Validate instrument name
        if (!InstrumentValidator::isValidInstrumentName($name)) {
            InstrumentValidator::logInvalidNameWarning($name);
            return new NoopAsynchronousGauge();
        }
        
        $unit = InstrumentValidator::normalizeUnit($unit);
        $description = InstrumentValidator::normalizeDescription($description);
        
        // Normalize callbacks to array
        if (!is_array($callbacks)) {
            $callbacks = $callbacks !== null ? [$callbacks] : [];
        }
        
        // Check if instrument already exists
        $key = $this->createInstrumentKey('async_gauge', $name, $unit, $description);
        
        if (isset($this->instruments[$key])) {
            $existing = $this->instruments[$key];
            
            if (!empty($callbacks) && method_exists($existing, 'addCallbacks')) {
                $existing->addCallbacks($callbacks);
            }
            
            return $existing;
        }
        
        // Create new asynchronous gauge
        $asyncGauge = new AsynchronousGauge(
            $name,
            $unit,
            $description,
            $callbacks,
            $this->name,
            $this->version,
            $this->schemaUrl
        );
        
        $this->instruments[$key] = $asyncGauge;
        
        return $asyncGauge;
    }
    
    /**
     * Create a unique key for instrument identification
     * 
     * Instruments are considered the same if they have the same:
     * - Type
     * - Case-insensitive name
     * - Case-sensitive unit
     * - Case-sensitive description
     */
    private function createInstrumentKey(
        string $type,
        string $name,
        string $unit,
        string $description
    ): string {
        return sprintf(
            '%s|%s|%s|%s',
            $type,
            strtolower($name), // Case-insensitive comparison
            $unit,             // Case-sensitive comparison
            $description       // Case-sensitive comparison
        );
    }
    
    /**
     * Check if advisory parameters match between instruments
     */
    private function advisoryParametersMatch(object $instrument, array $advisory): bool
    {
        // For simplicity, we always return true as advisory parameters
        // don't affect the identity of the instrument in our implementation
        return true;
    }
    
    /**
     * Shutdown this meter
     */
    public function shutdown(): void
    {
        // Cleanup any resources
        foreach ($this->instruments as $instrument) {
            if (method_exists($instrument, 'shutdown')) {
                $instrument->shutdown();
            }
        }
    }
    
    /**
     * Force flush any pending metrics
     */
    public function forceFlush(): void
    {
        foreach ($this->instruments as $instrument) {
            if (method_exists($instrument, 'flush')) {
                $instrument->flush();
            }
        }
    }
}

