<?php declare(strict_types=1);
/**
 * test Magento\Customer\Model\Metadata\Form\Text
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Customer\Test\Unit\Model\Metadata\Form;

use Magento\Customer\Api\Data\ValidationRuleInterface;
use Magento\Customer\Model\Metadata\Form\Text;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\StringUtils;

class TextTest extends AbstractFormTestCase
{
    /** @var StringUtils */
    protected $stringHelper;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->stringHelper = new StringUtils();
    }

    /**
     * Create an instance of the class that is being tested
     *
     * @param string|int|bool|null $value The value undergoing testing by a given test
     * @return Text
     */
    protected function getClass($value)
    {
        return new Text(
            $this->localeMock,
            $this->loggerMock,
            $this->attributeMetadataMock,
            $this->localeResolverMock,
            $value,
            0,
            false,
            $this->stringHelper
        );
    }

    /**
     * @param string|int|bool $value to assign to boolean
     * @param bool $expected text output
     * @dataProvider validateValueDataProvider
     */
    public function testValidateValue($value, $expected)
    {
        $sut = $this->getClass($value);
        $actual = $sut->validateValue($value);
        $this->assertEquals($expected, $actual);
    }

    /**
     * @return array
     */
    public function validateValueDataProvider()
    {
        return [
            'empty' => ['', true],
            '0' => [0, true],
            'zero' => ['0', true],
            'string' => ['some text', true],
            'number' => [123, true],
            'true' => [true, true],
            'false' => [false, true]
        ];
    }

    /**
     * @param string|int|bool|null $value to assign to boolean
     * @param string|bool|null $expected text output
     * @dataProvider validateValueRequiredDataProvider
     */
    public function testValidateValueRequired($value, $expected)
    {
        $this->attributeMetadataMock->expects($this->any())->method('isRequired')->willReturn(true);

        $sut = $this->getClass($value);
        $actual = $sut->validateValue($value);

        if (is_bool($actual)) {
            $this->assertEquals($expected, $actual);
        } else {
            if (is_array($actual)) {
                $actual = array_map(
                    function (Phrase $message) {
                        return $message->__toString();
                    },
                    $actual
                );
            }
            $this->assertContains($expected, $actual);
        }
    }

    /**
     * @return array
     */
    public function validateValueRequiredDataProvider()
    {
        return [
            'empty' => ['', '"" is a required value.'],
            'null' => [null, '"" is a required value.'],
            '0' => [0, '"" is a required value.'],
            'zero' => ['0', true],
            'string' => ['some text', true],
            'number' => [123, true],
            'true' => [true, true],
            'false' => [false, '"" is a required value.']
        ];
    }

    /**
     * @param string|int|bool|null $value to assign to boolean
     * @param string|bool $expected text output
     * @dataProvider validateValueLengthDataProvider
     */
    public function testValidateValueLength($value, $expected)
    {
        $minTextLengthRule = $this->getMockBuilder(ValidationRuleInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getName', 'getValue'])
            ->getMockForAbstractClass();
        $minTextLengthRule->expects($this->any())
            ->method('getName')
            ->willReturn('min_text_length');
        $minTextLengthRule->expects($this->any())
            ->method('getValue')
            ->willReturn(4);

        $maxTextLengthRule = $this->getMockBuilder(ValidationRuleInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getName', 'getValue'])
            ->getMockForAbstractClass();
        $maxTextLengthRule->expects($this->any())
            ->method('getName')
            ->willReturn('max_text_length');
        $maxTextLengthRule->expects($this->any())
            ->method('getValue')
            ->willReturn(8);

        $inputValidationRule = $this->getMockBuilder(ValidationRuleInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['getName', 'getValue'])
            ->getMockForAbstractClass();
        $inputValidationRule->expects($this->any())
            ->method('getName')
            ->willReturn('input_validation');
        $inputValidationRule->expects($this->any())
            ->method('getValue')
            ->willReturn('other');

        $validationRules = [
            'input_validation' => $inputValidationRule,
            'min_text_length' => $minTextLengthRule,
            'max_text_length' => $maxTextLengthRule,
        ];

        $this->attributeMetadataMock->expects(
            $this->any()
        )->method(
            'getValidationRules'
        )->willReturn(
            $validationRules
        );

        $sut = $this->getClass($value);
        $actual = $sut->validateValue($value);

        if (is_bool($actual)) {
            $this->assertEquals($expected, $actual);
        } else {
            if (is_array($actual)) {
                $actual = array_map(function (Phrase $message) {
                    return $message->__toString();
                }, $actual);
            }
            $this->assertContains($expected, $actual);
        }
    }

    /**
     * @return array
     */
    public function validateValueLengthDataProvider()
    {
        return [
            'false' => [false, true],
            'empty' => ['', true],
            'null' => [null, true],
            'true' => [true, '"" length must be equal or greater than 4 characters.'],
            'one' => [1, '"" length must be equal or greater than 4 characters.'],
            'L1' => ['a', '"" length must be equal or greater than 4 characters.'],
            'L3' => ['abc', '"" length must be equal or greater than 4 characters.'],
            'L4' => ['abcd', true],
            'thousand' => [1000, true],
            'L8' => ['abcdefgh', true],
            'L9' => ['abcdefghi', '"" length must be equal or less than 8 characters.'],
            'L12' => ['abcdefghjkl', '"" length must be equal or less than 8 characters.'],
            'billion' => [1000000000, '"" length must be equal or less than 8 characters.']
        ];
    }
}
