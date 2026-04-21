<?php

declare(strict_types=1);

namespace DDTrace\Tests\OpenFeature;

use DDTrace\OpenFeature\ContextFlattener;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ContextFlattener dot-notation flattening.
 *
 * Verifies EXPO-05: exposure subjects use dot-notation context flattening,
 * primitive-only values.
 */
class ContextFlattenerTest extends TestCase
{
    // ---------- Basic Flattening ----------

    public function testFlattenEmptyArray(): void
    {
        $this->assertSame([], ContextFlattener::flatten([]));
    }

    public function testFlattenFlatPrimitives(): void
    {
        $data = [
            'str' => 'hello',
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
        ];

        $this->assertSame($data, ContextFlattener::flatten($data));
    }

    public function testFlattenNestedArraysToDotNotation(): void
    {
        $data = [
            'user' => [
                'plan' => 'enterprise',
                'region' => 'us-east',
            ],
        ];

        $this->assertSame([
            'user.plan' => 'enterprise',
            'user.region' => 'us-east',
        ], ContextFlattener::flatten($data));
    }

    public function testFlattenDeeplyNested(): void
    {
        $data = [
            'level1' => [
                'level2' => [
                    'level3' => 'deep-value',
                ],
            ],
        ];

        $this->assertSame([
            'level1.level2.level3' => 'deep-value',
        ], ContextFlattener::flatten($data));
    }

    // ---------- Type Filtering ----------

    public function testDropsNullValues(): void
    {
        $data = ['key' => null, 'valid' => 'yes'];

        $this->assertSame(['valid' => 'yes'], ContextFlattener::flatten($data));
    }

    public function testDropsNumericKeys(): void
    {
        $data = [0 => 'first', 'name' => 'second'];

        $this->assertSame(['name' => 'second'], ContextFlattener::flatten($data));
    }

    public function testDropsObjectValues(): void
    {
        $data = [
            'obj' => new \stdClass(),
            'valid' => 'yes',
        ];

        $this->assertSame(['valid' => 'yes'], ContextFlattener::flatten($data));
    }

    public function testDropsNumericArrayValues(): void
    {
        // Arrays with numeric keys should be treated as "array values" and dropped
        // since they don't have string keys for dot-notation
        $data = [
            'tags' => ['a', 'b', 'c'],
            'valid' => 'yes',
        ];

        // The 'tags' array is recursed into, but numeric keys are dropped
        $this->assertSame(['valid' => 'yes'], ContextFlattener::flatten($data));
    }

    // ---------- Mixed Nested ----------

    public function testMixedNestedAndFlat(): void
    {
        $data = [
            'simple' => 'value',
            'nested' => [
                'inner' => 'deep',
                'number' => 99,
            ],
            'bool_val' => false,
        ];

        $this->assertSame([
            'simple' => 'value',
            'nested.inner' => 'deep',
            'nested.number' => 99,
            'bool_val' => false,
        ], ContextFlattener::flatten($data));
    }

    // ---------- Prefix Parameter ----------

    public function testFlattenWithPrefix(): void
    {
        $data = ['key' => 'value'];

        $this->assertSame(
            ['parent.key' => 'value'],
            ContextFlattener::flatten($data, 'parent'),
        );
    }

    // ---------- Edge Cases ----------

    public function testBooleanFalseIsPreserved(): void
    {
        $this->assertSame(
            ['flag' => false],
            ContextFlattener::flatten(['flag' => false]),
        );
    }

    public function testZeroIntegerIsPreserved(): void
    {
        $this->assertSame(
            ['count' => 0],
            ContextFlattener::flatten(['count' => 0]),
        );
    }

    public function testEmptyStringIsPreserved(): void
    {
        $this->assertSame(
            ['name' => ''],
            ContextFlattener::flatten(['name' => '']),
        );
    }

    public function testFloatZeroIsPreserved(): void
    {
        $this->assertSame(
            ['rate' => 0.0],
            ContextFlattener::flatten(['rate' => 0.0]),
        );
    }
}
