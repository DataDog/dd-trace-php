<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenTelemetry\Unit\Metrics;

use PHPUnit\Framework\TestCase;
use DDTrace\OpenTelemetry\Metrics\InstrumentValidator;

/**
 * Test cases for InstrumentValidator
 */
final class InstrumentValidatorTest extends TestCase
{
    public function testValidInstrumentNames(): void
    {
        $validNames = [
            'simple',
            'with_underscore',
            'with.dot',
            'with-dash',
            'with/slash',
            'mixedCase123',
            'a',
            'A',
            'http.server.request.duration',
            'process.runtime.php.memory',
        ];
        
        foreach ($validNames as $name) {
            $this->assertTrue(
                InstrumentValidator::isValidInstrumentName($name),
                "Expected '$name' to be valid"
            );
        }
    }
    
    public function testInvalidInstrumentNames(): void
    {
        $invalidNames = [
            '',                    // Empty
            null,                  // Null
            '123invalid',          // Starts with number
            '_startsWithUnderscore', // Starts with underscore
            'has space',           // Contains space
            'has@symbol',          // Contains invalid character
            'has#hash',            // Contains invalid character
            str_repeat('a', 256),  // Too long (> 255)
        ];
        
        foreach ($invalidNames as $name) {
            $this->assertFalse(
                InstrumentValidator::isValidInstrumentName($name),
                "Expected '" . ($name ?? 'null') . "' to be invalid"
            );
        }
    }
    
    public function testMaxLengthInstrumentName(): void
    {
        // Exactly 255 characters should be valid
        $validName = 'a' . str_repeat('b', 254);
        $this->assertTrue(InstrumentValidator::isValidInstrumentName($validName));
        
        // 256 characters should be invalid
        $invalidName = 'a' . str_repeat('b', 255);
        $this->assertFalse(InstrumentValidator::isValidInstrumentName($invalidName));
    }
    
    public function testValidExplicitBucketBoundaries(): void
    {
        $validBoundaries = [
            [1, 2, 3, 4, 5],
            [0.1, 0.5, 1.0, 5.0, 10.0],
            [10],
            [1, 10, 100, 1000],
        ];
        
        foreach ($validBoundaries as $boundaries) {
            $this->assertTrue(
                InstrumentValidator::isValidExplicitBucketBoundaries($boundaries),
                "Expected boundaries to be valid: " . json_encode($boundaries)
            );
        }
        
        // Null should be valid (means no boundaries specified)
        $this->assertTrue(InstrumentValidator::isValidExplicitBucketBoundaries(null));
    }
    
    public function testInvalidExplicitBucketBoundaries(): void
    {
        $invalidBoundaries = [
            [],                    // Empty array
            [5, 3, 1],            // Not in ascending order
            [1, 5, 3, 10],        // Not fully sorted
            [1, 'invalid', 3],    // Non-numeric value
        ];
        
        foreach ($invalidBoundaries as $boundaries) {
            $this->assertFalse(
                InstrumentValidator::isValidExplicitBucketBoundaries($boundaries),
                "Expected boundaries to be invalid: " . json_encode($boundaries)
            );
        }
    }
    
    public function testNormalizeUnit(): void
    {
        $this->assertSame('', InstrumentValidator::normalizeUnit(null));
        $this->assertSame('ms', InstrumentValidator::normalizeUnit('ms'));
        $this->assertSame('bytes', InstrumentValidator::normalizeUnit('bytes'));
    }
    
    public function testNormalizeDescription(): void
    {
        $this->assertSame('', InstrumentValidator::normalizeDescription(null));
        $this->assertSame('Test description', InstrumentValidator::normalizeDescription('Test description'));
    }
}

