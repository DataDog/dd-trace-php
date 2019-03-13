<?php

namespace DDTrace\Tests\Unit\Configuration;

use DDTrace\Configuration\EnvVariableRegistry;
use DDTrace\Tests\Unit\BaseTestCase;

final class EnvVariableRegistryTest extends BaseTestCase
{
    protected function setUp()
    {
        parent::setUp();
        putenv('DD_SOME_TEST_PARAMETER');
        putenv('DD_CUSTOM_PREFIX_SOME_TEST_PARAMETER');
    }

    public function testPrefixDefaultToDD()
    {
        putenv('DD_SOME_TEST_PARAMETER=bar');
        $registry = new EnvVariableRegistry();
        $this->assertSame('bar', $registry->stringValue('some.test.parameter', 'foo'));
    }

    public function testPrefixCanBeCustomized()
    {
        putenv('DD_CUSTOM_PREFIX_SOME_TEST_PARAMETER=bar');
        $registry = new EnvVariableRegistry('DD_CUSTOM_PREFIX_');
        $this->assertSame('bar', $registry->stringValue('some.test.parameter', 'foo'));
    }

    public function testStringFromEnv()
    {
        putenv('DD_SOME_TEST_PARAMETER=bar');
        $registry = new EnvVariableRegistry();
        $this->assertSame('bar', $registry->stringValue('some.test.parameter', 'foo'));
    }

    public function testStringWillFallbackToDefault()
    {
        $registry = new EnvVariableRegistry();
        $this->assertSame('foo', $registry->stringValue('some.test.parameter', 'foo'));
    }

    public function testTrueValueWhenEnvNotSet()
    {
        $registry = new EnvVariableRegistry();
        $this->assertTrue($registry->boolValue('some.test.parameter', true));
    }

    public function testFalseValueWhenEnvNotSet()
    {
        $registry = new EnvVariableRegistry();
        $this->assertFalse($registry->boolValue('some.test.parameter', false));
    }

    public function testBoolValueTrueEnvSetWord()
    {
        $registry = new EnvVariableRegistry();
        putenv('DD_SOME_TEST_PARAMETER=tRuE   ');
        $this->assertTrue($registry->boolValue('some.test.parameter', false));
    }

    public function testBoolValueTrueEnvSetNumber()
    {
        $registry = new EnvVariableRegistry();
        putenv('DD_SOME_TEST_PARAMETER=1   ');
        $this->assertTrue($registry->boolValue('some.test.parameter', false));
    }

    public function testBoolValueFalseEnvSetWord()
    {
        $registry = new EnvVariableRegistry();
        putenv('DD_SOME_TEST_PARAMETER=fAlSe   ');
        $this->assertFalse($registry->boolValue('some.test.parameter', true));
    }

    public function testBoolValueFalseEnvSetNumber()
    {
        $registry = new EnvVariableRegistry();
        putenv('DD_SOME_TEST_PARAMETER=0   ');
        $this->assertFalse($registry->boolValue('some.test.parameter', true));
    }

    public function testFloatValueProvided()
    {
        $registry = new EnvVariableRegistry();
        putenv('DD_SOME_TEST_PARAMETER=0.7   ');
        $this->assertSame(0.7, $registry->floatValue('some.test.parameter', 1));
    }

    public function testFloatValueNot()
    {
        $registry = new EnvVariableRegistry();
        $this->assertSame(1.0, $registry->floatValue('some.test.parameter', 1));
    }

    public function testFloatValueAlwaysConvertedToFloat()
    {
        $registry = new EnvVariableRegistry();
        putenv('DD_SOME_TEST_PARAMETER=1   ');
        $this->assertEquals(1.0, $registry->floatValue('some.test.parameter', 1));
    }

    public function testFloatValueOverMax()
    {
        $registry = new EnvVariableRegistry();
        putenv('DD_SOME_TEST_PARAMETER=10000   ');
        $this->assertEquals(1.0, $registry->floatValue('some.test.parameter', 1, 0, 1));
    }

    public function testFloatValueBelowMin()
    {
        $registry = new EnvVariableRegistry();
        putenv('DD_SOME_TEST_PARAMETER=0   ');
        $this->assertEquals(1.0, $registry->floatValue('some.test.parameter', 1, 1, 2));
    }

    public function testInArrayNotSet()
    {
        $registry = new EnvVariableRegistry();
        $this->assertFalse($registry->inArray('some.test.parameter', 'name'));
    }

    public function testInArraySet()
    {
        $registry = new EnvVariableRegistry();
        putenv('DD_SOME_TEST_PARAMETER=value1,value2');
        $this->assertTrue($registry->inArray('some.test.parameter', 'value1'));
        $this->assertTrue($registry->inArray('some.test.parameter', 'value2'));
        $this->assertFalse($registry->inArray('some.test.parameter', 'value3'));
    }

    public function testInArrayCaseInsensitive()
    {
        $registry = new EnvVariableRegistry();
        putenv('DD_SOME_TEST_PARAMETER=vAlUe1,VaLuE2');
        $this->assertTrue($registry->inArray('some.test.parameter', 'value1'));
        $this->assertTrue($registry->inArray('some.test.parameter', 'value2'));
        $this->assertFalse($registry->inArray('some.test.parameter', 'value3'));
    }

    public function testInArrayWhiteSpaceBetweenDefinitions()
    {
        $registry = new EnvVariableRegistry();
        putenv('DD_SOME_TEST_PARAMETER= value1    ,     value2     ');
        $this->assertTrue($registry->inArray('some.test.parameter', 'value1'));
        $this->assertTrue($registry->inArray('some.test.parameter', 'value2'));
        $this->assertFalse($registry->inArray('some.test.parameter', 'value3'));
    }

    public function testAssociativeStringArrayNotPersistingDefaultValue()
    {
        $registry = new EnvVariableRegistry();
        $this->assertSame([], $registry->associativeStringArrayValue('some.test.parameter'));

        putenv('DD_SOME_TEST_PARAMETER=key:value');

        $this->assertSame(['key' => 'value'], $registry->associativeStringArrayValue('some.test.parameter'));
    }

    /**
     * @dataProvider associativeStringArrayDataProvider
     * @param string|null $env
     * @param string $expected
     */
    public function testAssociativeStringArray($env, $expected)
    {
        if (null !== $env) {
            putenv('DD_SOME_TEST_PARAMETER=' . $env);
        }
        $registry = new EnvVariableRegistry();
        $this->assertSame($expected, $registry->associativeStringArrayValue('some.test.parameter'));
    }

    public function associativeStringArrayDataProvider()
    {
        return [

            'env not set' => [null, []],

            'empty string' => ['', []],

            'only key' => ['only_key', []],

            'empty key' => [':value', []],

            'one value' => ['  key:   value    ', ['key' => 'value']],

            'multiple values' => ['  key1:   value1  , key2 :value2  ', ['key1' => 'value1', 'key2' => 'value2']],

            'preserves case' => ['  kEy1:   vAlUe1  ', ['kEy1' => 'vAlUe1']],

            'tolerant to errors' => ['  kEy1:   vAlUe1 : wrong  ', []],
        ];
    }
}
