<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenTelemetry\Unit\Metrics;

use PHPUnit\Framework\TestCase;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\API\Metrics\GlobalMeterProvider;

/**
 * Test cases for OpenTelemetry Metrics MeterProvider
 */
final class MeterProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset global state before each test
        GlobalMeterProvider::reset();
    }
    
    protected function tearDown(): void
    {
        GlobalMeterProvider::reset();
        parent::tearDown();
    }
    
    public function testMeterProviderCreation(): void
    {
        $meterProvider = new MeterProvider();
        
        $this->assertInstanceOf(MeterProvider::class, $meterProvider);
    }
    
    public function testGetMeterReturnsMeterInstance(): void
    {
        $meterProvider = new MeterProvider();
        $meter = $meterProvider->getMeter('test-service');
        
        $this->assertNotNull($meter);
        $this->assertInstanceOf(\OpenTelemetry\SDK\Metrics\Meter::class, $meter);
    }
    
    public function testGetMeterReturnsSameMeterForSameParameters(): void
    {
        $meterProvider = new MeterProvider();
        
        $meter1 = $meterProvider->getMeter('test-service', '1.0.0');
        $meter2 = $meterProvider->getMeter('test-service', '1.0.0');
        
        $this->assertSame($meter1, $meter2);
    }
    
    public function testGetMeterReturnsDifferentMeterForDifferentNames(): void
    {
        $meterProvider = new MeterProvider();
        
        $meter1 = $meterProvider->getMeter('service1');
        $meter2 = $meterProvider->getMeter('service2');
        
        $this->assertNotSame($meter1, $meter2);
    }
    
    public function testGetMeterReturnsDifferentMeterForDifferentVersions(): void
    {
        $meterProvider = new MeterProvider();
        
        $meter1 = $meterProvider->getMeter('test-service', '1.0.0');
        $meter2 = $meterProvider->getMeter('test-service', '2.0.0');
        
        $this->assertNotSame($meter1, $meter2);
    }
    
    public function testGlobalMeterProviderGet(): void
    {
        $meterProvider = GlobalMeterProvider::get();
        
        $this->assertNotNull($meterProvider);
    }
    
    public function testGlobalMeterProviderSet(): void
    {
        $customMeterProvider = new MeterProvider();
        GlobalMeterProvider::set($customMeterProvider);
        
        $retrievedProvider = GlobalMeterProvider::get();
        
        $this->assertSame($customMeterProvider, $retrievedProvider);
    }
}

