<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Validator\Test\Unit\Constraint;

/**
 * Test case for \Magento\Framework\Validator\Constraint\Property
 */
class PropertyTest extends \PHPUnit\Framework\TestCase
{
    const PROPERTY_NAME = 'test';

    /**
     * @var \Magento\Framework\Validator\Constraint\Property
     */
    protected $_constraint;

    /**
     * @var \Magento\Framework\Validator\ValidatorInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $_validatorMock;

    /**
     * Set up
     */
    protected function setUp(): void
    {
        $this->_validatorMock = $this->createMock(\Magento\Framework\Validator\ValidatorInterface::class);
        $this->_constraint = new \Magento\Framework\Validator\Constraint\Property(
            $this->_validatorMock,
            self::PROPERTY_NAME
        );
    }

    /**
     * Test getAlias method
     */
    public function testGetAlias()
    {
        $this->assertEmpty($this->_constraint->getAlias());
        $alias = 'foo';
        $constraint = new \Magento\Framework\Validator\Constraint\Property(
            $this->_validatorMock,
            self::PROPERTY_NAME,
            $alias
        );
        $this->assertEquals($alias, $constraint->getAlias());
    }

    /**
     * Test isValid method
     *
     * @dataProvider isValidDataProvider
     *
     * @param mixed $value
     * @param mixed $validateValue
     * @param bool $expectedResult
     * @param array $validatorMessages
     * @param array $expectedMessages
     */
    public function testIsValid(
        $value,
        $validateValue,
        $expectedResult,
        $validatorMessages = [],
        $expectedMessages = []
    ) {
        $this->_validatorMock->expects(
            $this->once()
        )->method(
            'isValid'
        )->with(
            $validateValue
        )->willReturn(
            $expectedResult
        );

        if ($expectedResult) {
            $this->_validatorMock->expects($this->never())->method('getMessages');
        } else {
            $this->_validatorMock->expects(
                $this->once()
            )->method(
                'getMessages'
            )->willReturn(
                $validatorMessages
            );
        }

        $this->assertEquals($expectedResult, $this->_constraint->isValid($value));
        $this->assertEquals($expectedMessages, $this->_constraint->getMessages());
    }

    /**
     * Data provider for testIsValid
     *
     * @return array
     */
    public function isValidDataProvider()
    {
        return [
            [[self::PROPERTY_NAME => 'Property value', 'foo' => 'Foo value'], 'Property value', true],
            [
                new \Magento\Framework\DataObject([self::PROPERTY_NAME => 'Property value']),
                'Property value',
                true
            ],
            [new \ArrayObject([self::PROPERTY_NAME => 'Property value']), 'Property value', true],
            [
                [self::PROPERTY_NAME => 'Property value', 'foo' => 'Foo value'],
                'Property value',
                false,
                ['Error message 1', 'Error message 2'],
                [self::PROPERTY_NAME => ['Error message 1', 'Error message 2']]
            ],
            [
                ['foo' => 'Foo value'],
                null,
                false,
                ['Error message 1'],
                [self::PROPERTY_NAME => ['Error message 1']]
            ],
            [
                'scalar',
                null,
                false,
                ['Error message 1'],
                [self::PROPERTY_NAME => ['Error message 1']]
            ]
        ];
    }
}
