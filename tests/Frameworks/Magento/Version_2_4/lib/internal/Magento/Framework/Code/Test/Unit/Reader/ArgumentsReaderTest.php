<?php
/**
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Code\Test\Unit\Reader;

use PHPUnit\Framework\TestCase;
use Magento\Framework\Code\Reader\ArgumentsReader;

require_once __DIR__ . '/_files/ClassesForArgumentsReader.php';
class ArgumentsReaderTest extends TestCase
{
    /**
     * @var ArgumentsReader
     */
    protected $_model;

    protected function setUp(): void
    {
        $this->_model = new ArgumentsReader();
    }

    public function testGetConstructorArgumentsClassWithAllArgumentsType()
    {
        $expectedResult = [
            'stdClassObject' => [
                'name' => 'stdClassObject',
                'position' => 0,
                'type' => '\stdClass',
                'isOptional' => false,
                'default' => null,
            ],
            'withoutConstructorClassObject' => [
                'name' => 'withoutConstructorClassObject',
                'position' => 1,
                'type' => '\ClassWithoutConstruct',
                'isOptional' => false,
                'default' => null,
            ],
            'someVariable' => [
                'name' => 'someVariable',
                'position' => 2,
                'type' => 'mixed',
                'isOptional' => false,
                'default' => null,
            ],
            'noType' => [
                'name' => 'noType',
                'position' => 3,
                'type' => '\\\\noType',
                'isOptional' => false,
                'default' => null,
            ],
            'const' => [
                'name' => 'const',
                'position' => 4,
                'type' => 'string',
                'isOptional' => true,
                'default' => 'Const Value',
            ],
            'optionalNumValue' => [
                'name' => 'optionalNumValue',
                'position' => 5,
                'type' => 'int',
                'isOptional' => true,
                'default' => 9807,
            ],
            'optionalStringValue' => [
                'name' => 'optionalStringValue',
                'position' => 6,
                'type' => 'string',
                'isOptional' => true,
                'default' => 'optional string',
            ],
            'optionalArrayValue' => [
                'name' => 'optionalArrayValue',
                'position' => 7,
                'type' => 'array',
                'isOptional' => true,
                'default' => "array('optionalKey' => 'optionalValue')",
            ],
            'optNullValue' => [
                'name' => 'optNullValue',
                'position' => 8,
                'type' => null,
                'isOptional' => true,
                'default' => null,
            ],
            'optNullIntValue' => [
                'name' => 'optNullIntValue',
                'position' => 9,
                'type' => null,
                'isOptional' => true,
                'default' => 1,
            ],
            'optNoTypeValue' => [
                'name' => 'optNoTypeValue',
                'position' => 10,
                'type' => '\\\\optNoTypeValue',
                'isOptional' => true,
                'default' => null,
            ],
        ];
        $class = new \ReflectionClass('ClassWithAllArgumentTypes');
        $actualResult = $this->_model->getConstructorArguments($class);

        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testGetConstructorArgumentsClassWithoutOwnConstructorInheritedFalse()
    {
        $class = new \ReflectionClass('classWithoutOwnConstruct');
        $actualResult = $this->_model->getConstructorArguments($class);

        $this->assertEquals([], $actualResult);
    }

    public function testGetConstructorArgumentsClassWithoutOwnConstructorInheritedTrue()
    {
        $expectedResult = [
            'stdClassObject' => [
                'name' => 'stdClassObject',
                'position' => 0,
                'type' => '\stdClass',
                'isOptional' => false,
                'default' => null,
            ],
            'withoutConstructorClassObject' => [
                'name' => 'withoutConstructorClassObject',
                'position' => 1,
                'type' => '\ClassWithoutConstruct',
                'isOptional' => false,
                'default' => null,
            ],
            'someVariable' => [
                'name' => 'someVariable',
                'position' => 2,
                'type' => 'mixed',
                'isOptional' => false,
                'default' => null,
            ],
            'noType' => [
                'name' => 'noType',
                'position' => 3,
                'type' => '\\\\noType',
                'isOptional' => false,
                'default' => null,
            ],
            'const' => [
                'name' => 'const',
                'position' => 4,
                'type' => 'string',
                'isOptional' => true,
                'default' => 'Const Value',
            ],
            'optionalNumValue' => [
                'name' => 'optionalNumValue',
                'position' => 5,
                'type' => 'int',
                'isOptional' => true,
                'default' => 9807,
            ],
            'optionalStringValue' => [
                'name' => 'optionalStringValue',
                'position' => 6,
                'type' => 'string',
                'isOptional' => true,
                'default' => 'optional string',
            ],
            'optionalArrayValue' => [
                'name' => 'optionalArrayValue',
                'position' => 7,
                'type' => 'array',
                'isOptional' => true,
                'default' => "array('optionalKey' => 'optionalValue')",
            ],
            'optNullValue' => [
                'name' => 'optNullValue',
                'position' => 8,
                'type' => null,
                'isOptional' => true,
                'default' => null,
            ],
            'optNullIntValue' => [
                'name' => 'optNullIntValue',
                'position' => 9,
                'type' => null,
                'isOptional' => true,
                'default' => 1,
            ],
            'optNoTypeValue' => [
                'name' => 'optNoTypeValue',
                'position' => 10,
                'type' => '\\\\optNoTypeValue',
                'isOptional' => true,
                'default' => null,
            ],
        ];
        $class = new \ReflectionClass('ClassWithoutOwnConstruct');
        $actualResult = $this->_model->getConstructorArguments($class, false, true);

        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testGetConstructorArgumentsClassWithoutConstructInheridetFalse()
    {
        $class = new \ReflectionClass('ClassWithoutConstruct');
        $actualResult = $this->_model->getConstructorArguments($class);

        $this->assertEquals([], $actualResult);
    }

    public function testGetConstructorArgumentsClassWithoutConstructInheridetTrue()
    {
        $class = new \ReflectionClass('ClassWithoutConstruct');
        $actualResult = $this->_model->getConstructorArguments($class, false, true);

        $this->assertEquals([], $actualResult);
    }

    public function testGetConstructorArgumentsClassExtendsDefaultPhpTypeInheridetFalse()
    {
        $class = new \ReflectionClass('ClassExtendsDefaultPhpType');
        $actualResult = $this->_model->getConstructorArguments($class);

        $this->assertEquals([], $actualResult);
    }

    public function testGetConstructorArgumentsClassExtendsDefaultPhpTypeInheridetTrue()
    {
        $expectedResult = [
            'message' => [
                'name' => 'message',
                'position' => 0,
                'type' => 'string',
                'isOptional' => true,
                'default' => '',
            ],
            'code' => [
                'name' => 'code',
                'position' => 1,
                'type' => 'int',
                'isOptional' => true,
                'default' => 0,
            ],
            'previous' => [
                'name' => 'previous',
                'position' => 2,
                'type' => '\Exception',
                'isOptional' => true,
                'default' => null,
            ],
        ];
        $class = new \ReflectionClass('ClassExtendsDefaultPhpTypeWithIOverrideConstructor');
        $actualResult = $this->_model->getConstructorArguments($class, false, true);

        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testGetParentCallWithRightArgumentsOrder()
    {
        $class = new \ReflectionClass('ThirdClassForParentCall');
        $actualResult = $this->_model->getParentCall(
            $class,
            [
                'stdClassObject' => ['type' => '\stdClass'],
                'secondClass' => ['type' => '\ClassExtendsDefaultPhpType']
            ]
        );
        $expectedResult = [
            [
                'name' => 'stdClassObject',
                'position' => 0,
                'type' => '\stdClass',
                'isNamedArgument' => false
            ],
            [
                'name' => 'secondClass',
                'position' => 1,
                'type' => '\ClassExtendsDefaultPhpType',
                'isNamedArgument' => false
            ],
        ];
        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testGetParentCallWithWrongArgumentsOrder()
    {
        $class = new \ReflectionClass('WrongArgumentsOrder');
        $actualResult = $this->_model->getParentCall(
            $class,
            [
                'stdClassObject' => ['type' => '\stdClass'],
                'secondClass' => ['type' => '\ClassExtendsDefaultPhpType']
            ]
        );
        $expectedResult = [
            [
                'name' => 'secondClass',
                'position' => 0,
                'type' => '\ClassExtendsDefaultPhpType',
                'isNamedArgument' => false],
            [
                'name' => 'stdClassObject',
                'position' => 1,
                'type' => '\stdClass',
                'isNamedArgument' => false
            ],
        ];
        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testGetParentCallWithSeparateLineFormat()
    {
        $class = new \ReflectionClass('ThirdClassForParentCall');
        $actualResult = $this->_model->getParentCall(
            $class,
            [
                'stdClassObject' => ['type' => '\stdClass'],
                'secondClass' => ['type' => '\ClassExtendsDefaultPhpType']
            ]
        );
        $expectedResult = [
            [
                'name' => 'stdClassObject',
                'position' => 0,
                'type' => '\stdClass',
                'isNamedArgument' => false
            ],
            [
                'name' => 'secondClass',
                'position' => 1,
                'type' => '\ClassExtendsDefaultPhpType',
                'isNamedArgument' => false
            ],
        ];
        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testGetParentCallWithNamedArguments()
    {
        $class = new \ReflectionClass('ClassWithNamedArgumentsForParentCall');
        $actualResult = $this->_model->getParentCall(
            $class,
            [
                'stdClassObject' => ['type' => '\stdClass'],
                'runeTimeException' => ['type' => '\ClassExtendsDefaultPhpType']
            ]
        );
        $expectedResult = [
            [
                'name' => 'stdClassObject',
                'position' => 0,
                'type' => '\stdClass',
                'isNamedArgument' => true
            ],
            [
                'name' => 'runeTimeException',
                'position' => 1,
                'type' => '\ClassExtendsDefaultPhpType',
                'isNamedArgument' => true
            ],
        ];
        $this->assertEquals($expectedResult, $actualResult);
    }

    public function testGetParentCallWithMixedArguments()
    {
        $class = new \ReflectionClass('ClassWithMixedArgumentsForParentCall');
        $actualResult = $this->_model->getParentCall(
            $class,
            [
                'stdClassObject' => ['type' => '\stdClass'],
                'runeTimeException' => ['type' => '\ClassExtendsDefaultPhpType']
            ]
        );
        $expectedResult = [
            [
                'name' => 'stdClassObject',
                'position' => 0,
                'type' => '\stdClass',
                'isNamedArgument' => false
            ],
            [
                'name' => 'runeTimeException',
                'position' => 1,
                'type' => '\ClassExtendsDefaultPhpType',
                'isNamedArgument' => true
            ],
        ];
        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * @param string $requiredType
     * @param string $actualType
     * @param bool $expectedResult
     * @dataProvider testIsCompatibleTypeDataProvider
     */
    public function testIsCompatibleType($requiredType, $actualType, $expectedResult)
    {
        $actualResult = $this->_model->isCompatibleType($requiredType, $actualType);
        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * @return array
     */
    public function testIsCompatibleTypeDataProvider()
    {
        return [
            ['array', 10, false],
            ['array', 'array', true],
            [null, null, true],
            [null, 'array', true],
            ['\ClassWithAllArgumentTypes', '\ClassWithoutOwnConstruct', true],
            ['\ClassWithoutOwnConstruct', '\ClassWithAllArgumentTypes', false]
        ];
    }

    public function testGetAnnotations()
    {
        $class = new \ReflectionClass('\ClassWithSuppressWarnings');
        $expected = ['SuppressWarnings' => 'Magento.TypeDuplication'];
        $this->assertEquals($expected, $this->_model->getAnnotations($class));
    }
}
