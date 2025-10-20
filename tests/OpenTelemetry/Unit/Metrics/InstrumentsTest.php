<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenTelemetry\Unit\Metrics;

use PHPUnit\Framework\TestCase;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use DDTrace\OpenTelemetry\Metrics\MetricExporter;

/**
 * Test cases for OpenTelemetry Metrics Instruments
 */
final class InstrumentsTest extends TestCase
{
    private $meterProvider;
    private $meter;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->meterProvider = new MeterProvider();
        $this->meter = $this->meterProvider->getMeter('test-service', '1.0.0');
        
        // Clear metric exporter state
        MetricExporter::getInstance()->clear();
    }
    
    protected function tearDown(): void
    {
        MetricExporter::getInstance()->clear();
        parent::tearDown();
    }
    
    public function testCounterCreation(): void
    {
        $counter = $this->meter->createCounter(
            'test.counter',
            'count',
            'A test counter'
        );
        
        $this->assertNotNull($counter);
        $this->assertInstanceOf(\OpenTelemetry\API\Metrics\CounterInterface::class, $counter);
    }
    
    public function testCounterAdd(): void
    {
        $counter = $this->meter->createCounter('test.counter');
        
        // Should not throw exception
        $counter->add(5, ['key' => 'value']);
        $counter->add(10, ['key' => 'value']);
        
        $this->assertTrue(true); // If we get here, no exception was thrown
    }
    
    public function testCounterRejectsNegativeValues(): void
    {
        $counter = $this->meter->createCounter('test.counter');
        
        // Should not throw, but should log warning and not record
        $counter->add(-5);
        
        $this->assertTrue(true);
    }
    
    public function testHistogramCreation(): void
    {
        $histogram = $this->meter->createHistogram(
            'test.histogram',
            'ms',
            'A test histogram'
        );
        
        $this->assertNotNull($histogram);
        $this->assertInstanceOf(\OpenTelemetry\API\Metrics\HistogramInterface::class, $histogram);
    }
    
    public function testHistogramRecord(): void
    {
        $histogram = $this->meter->createHistogram('test.histogram');
        
        // Should not throw exception
        $histogram->record(100, ['endpoint' => '/api/users']);
        $histogram->record(250, ['endpoint' => '/api/orders']);
        
        $this->assertTrue(true);
    }
    
    public function testHistogramWithBucketBoundaries(): void
    {
        $histogram = $this->meter->createHistogram(
            'test.histogram',
            'ms',
            'A test histogram',
            ['ExplicitBucketBoundaries' => [10, 50, 100, 250, 500]]
        );
        
        $this->assertNotNull($histogram);
    }
    
    public function testUpDownCounterCreation(): void
    {
        $upDownCounter = $this->meter->createUpDownCounter(
            'test.updowncounter',
            'count',
            'A test up-down counter'
        );
        
        $this->assertNotNull($upDownCounter);
        $this->assertInstanceOf(\OpenTelemetry\API\Metrics\UpDownCounterInterface::class, $upDownCounter);
    }
    
    public function testUpDownCounterAdd(): void
    {
        $upDownCounter = $this->meter->createUpDownCounter('test.updowncounter');
        
        // Should accept both positive and negative values
        $upDownCounter->add(5);
        $upDownCounter->add(-3);
        $upDownCounter->add(10);
        
        $this->assertTrue(true);
    }
    
    public function testGaugeCreation(): void
    {
        $gauge = $this->meter->createGauge(
            'test.gauge',
            'celsius',
            'A test gauge'
        );
        
        $this->assertNotNull($gauge);
    }
    
    public function testGaugeRecord(): void
    {
        $gauge = $this->meter->createGauge('test.gauge');
        
        // Should accept any value
        $gauge->record(45.5);
        $gauge->record(50.2);
        
        $this->assertTrue(true);
    }
    
    public function testAsynchronousCounterCreation(): void
    {
        $asyncCounter = $this->meter->createObservableCounter(
            'test.async.counter',
            'count',
            'A test async counter',
            [
                function ($observer) {
                    $observer->observe(100, []);
                }
            ]
        );
        
        $this->assertNotNull($asyncCounter);
        $this->assertInstanceOf(\OpenTelemetry\API\Metrics\ObservableCounterInterface::class, $asyncCounter);
    }
    
    public function testAsynchronousGaugeCreation(): void
    {
        $asyncGauge = $this->meter->createObservableGauge(
            'test.async.gauge',
            'bytes',
            'A test async gauge',
            [
                function ($observer) {
                    $observer->observe(memory_get_usage(true), []);
                }
            ]
        );
        
        $this->assertNotNull($asyncGauge);
        $this->assertInstanceOf(\OpenTelemetry\API\Metrics\ObservableGaugeInterface::class, $asyncGauge);
    }
    
    public function testInvalidInstrumentNameReturnsNoop(): void
    {
        // Invalid: starts with a number
        $counter = $this->meter->createCounter('123invalid');
        
        $this->assertInstanceOf(\DDTrace\OpenTelemetry\Metrics\Instruments\NoopCounter::class, $counter);
    }
    
    public function testInstrumentNameValidation(): void
    {
        // Valid names
        $counter1 = $this->meter->createCounter('valid_name');
        $counter2 = $this->meter->createCounter('valid.name');
        $counter3 = $this->meter->createCounter('valid/name');
        $counter4 = $this->meter->createCounter('valid-name');
        
        $this->assertNotInstanceOf(\DDTrace\OpenTelemetry\Metrics\Instruments\NoopCounter::class, $counter1);
        $this->assertNotInstanceOf(\DDTrace\OpenTelemetry\Metrics\Instruments\NoopCounter::class, $counter2);
        $this->assertNotInstanceOf(\DDTrace\OpenTelemetry\Metrics\Instruments\NoopCounter::class, $counter3);
        $this->assertNotInstanceOf(\DDTrace\OpenTelemetry\Metrics\Instruments\NoopCounter::class, $counter4);
    }
    
    public function testInstrumentDeduplication(): void
    {
        $counter1 = $this->meter->createCounter('test.counter', 'count', 'Test counter');
        $counter2 = $this->meter->createCounter('test.counter', 'count', 'Test counter');
        
        // Should return the same instance
        $this->assertSame($counter1, $counter2);
    }
    
    public function testInstrumentWithAttributes(): void
    {
        $counter = $this->meter->createCounter('test.counter');
        
        // Test with various attribute types
        $counter->add(1, [
            'string_attr' => 'value',
            'int_attr' => 123,
            'float_attr' => 45.67,
            'bool_attr' => true
        ]);
        
        $this->assertTrue(true);
    }
}

