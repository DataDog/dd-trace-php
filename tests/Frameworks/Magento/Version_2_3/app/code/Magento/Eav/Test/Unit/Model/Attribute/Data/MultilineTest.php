<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Eav\Test\Unit\Model\Attribute\Data;

use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

class MultilineTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Eav\Model\Attribute\Data\Multiline
     */
    protected $model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Framework\Stdlib\StringUtils
     */
    protected $stringMock;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        /** @var TimezoneInterface $timezoneMock */
        $timezoneMock = $this->getMockForAbstractClass(TimezoneInterface::class);
        /** @var \Psr\Log\LoggerInterface $loggerMock */
        $loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);
        /** @var ResolverInterface $localeResolverMock */
        $localeResolverMock = $this->getMockForAbstractClass(ResolverInterface::class);
        $this->stringMock = $this->createMock(\Magento\Framework\Stdlib\StringUtils::class);

        $this->model = new \Magento\Eav\Model\Attribute\Data\Multiline(
            $timezoneMock,
            $loggerMock,
            $localeResolverMock,
            $this->stringMock
        );
    }

    /**
     * @covers       \Magento\Eav\Model\Attribute\Data\Multiline::extractValue
     *
     * @param mixed $param
     * @param mixed $expectedResult
     * @dataProvider extractValueDataProvider
     */
    public function testExtractValue($param, $expectedResult)
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Framework\App\RequestInterface $requestMock */
        $requestMock = $this->createMock(\Magento\Framework\App\RequestInterface::class);
        /** @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Eav\Model\Attribute $attributeMock */
        $attributeMock = $this->createMock(\Magento\Eav\Model\Attribute::class);

        $requestMock->expects($this->once())->method('getParam')->willReturn($param);
        $attributeMock->expects($this->once())
            ->method('getAttributeCode')
            ->willReturn('attributeCode');

        $this->model->setAttribute($attributeMock);
        $this->assertEquals($expectedResult, $this->model->extractValue($requestMock));
    }

    /**
     * @return array
     */
    public function extractValueDataProvider()
    {
        return [
            [
                'param' => 'param',
                'expectedResult' => false,
            ],
            [
                'param' => ['param'],
                'expectedResult' => ['param']
            ],
        ];
    }

    /**
     * @covers       \Magento\Eav\Model\Attribute\Data\Multiline::outputValue
     *
     * @param string $format
     * @param mixed $expectedResult
     * @dataProvider outputValueDataProvider
     */
    public function testOutputValue($format, $expectedResult)
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Framework\Model\AbstractModel $entityMock */
        $entityMock = $this->createMock(\Magento\Framework\Model\AbstractModel::class);
        $entityMock->expects($this->once())
            ->method('getData')
            ->willReturn("value1\nvalue2");

        /** @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Eav\Model\Attribute $attributeMock */
        $attributeMock = $this->createMock(\Magento\Eav\Model\Attribute::class);

        $this->model->setEntity($entityMock);
        $this->model->setAttribute($attributeMock);
        $this->assertEquals($expectedResult, $this->model->outputValue($format));
    }

    /**
     * @return array
     */
    public function outputValueDataProvider()
    {
        return [
            [
                'format' => \Magento\Eav\Model\AttributeDataFactory::OUTPUT_FORMAT_ARRAY,
                'expectedResult' => ['value1', 'value2'],
            ],
            [
                'format' => \Magento\Eav\Model\AttributeDataFactory::OUTPUT_FORMAT_HTML,
                'expectedResult' => 'value1<br />value2'
            ],
            [
                'format' => \Magento\Eav\Model\AttributeDataFactory::OUTPUT_FORMAT_ONELINE,
                'expectedResult' => 'value1 value2'
            ],
            [
                'format' => \Magento\Eav\Model\AttributeDataFactory::OUTPUT_FORMAT_TEXT,
                'expectedResult' => "value1\nvalue2"
            ]
        ];
    }

    /**
     * @covers       \Magento\Eav\Model\Attribute\Data\Multiline::validateValue
     * @covers       \Magento\Eav\Model\Attribute\Data\Text::validateValue
     *
     * @param mixed $value
     * @param bool $isAttributeRequired
     * @param array $rules
     * @param array $expectedResult
     * @dataProvider validateValueDataProvider
     */
    public function testValidateValue($value, $isAttributeRequired, $rules, $expectedResult)
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Framework\Model\AbstractModel $entityMock */
        $entityMock = $this->createMock(\Magento\Framework\Model\AbstractModel::class);
        $entityMock->expects($this->any())
            ->method('getDataUsingMethod')
            ->willReturn("value1\nvalue2");

        /** @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Eav\Model\Attribute $attributeMock */
        $attributeMock = $this->createMock(\Magento\Eav\Model\Attribute::class);
        $attributeMock->expects($this->any())->method('getMultilineCount')->willReturn(2);
        $attributeMock->expects($this->any())->method('getValidateRules')->willReturn($rules);
        $attributeMock->expects($this->any())
            ->method('getStoreLabel')
            ->willReturn('Label');

        $attributeMock->expects($this->any())
            ->method('getIsRequired')
            ->willReturn($isAttributeRequired);

        $this->stringMock->expects($this->any())->method('strlen')->willReturn(5);

        $this->model->setEntity($entityMock);
        $this->model->setAttribute($attributeMock);
        $this->assertEquals($expectedResult, $this->model->validateValue($value));
    }

    /**
     * @return array
     */
    public function validateValueDataProvider()
    {
        return [
            [
                'value' => false,
                'isAttributeRequired' => false,
                'rules' => [],
                'expectedResult' => true,
            ],
            [
                'value' => 'value',
                'isAttributeRequired' => false,
                'rules' => [],
                'expectedResult' => true,
            ],
            [
                'value' => ['value1', 'value2'],
                'isAttributeRequired' => false,
                'rules' => [],
                'expectedResult' => true,
            ],
            [
                'value' => 'value',
                'isAttributeRequired' => false,
                'rules' => ['input_validation' => 'other', 'max_text_length' => 3],
                'expectedResult' => ['"Label" length must be equal or less than 3 characters.'],
            ],
            [
                'value' => 'value',
                'isAttributeRequired' => false,
                'rules' => ['input_validation' => 'other', 'min_text_length' => 10],
                'expectedResult' => ['"Label" length must be equal or greater than 10 characters.'],
            ],
            [
                'value' => "value1\nvalue2\nvalue3",
                'isAttributeRequired' => false,
                'rules' => [],
                'expectedResult' => ['"Label" cannot contain more than 2 lines.'],
            ],
            [
                'value' => ['value1', 'value2', 'value3'],
                'isAttributeRequired' => false,
                'rules' => [],
                'expectedResult' => ['"Label" cannot contain more than 2 lines.'],
            ],
            [
                'value' => [],
                'isAttributeRequired' => true,
                'rules' => [],
                'expectedResult' => ['"Label" is a required value.'],
            ],
            [
                'value' => '',
                'isAttributeRequired' => true,
                'rules' => [],
                'expectedResult' => ['"Label" is a required value.'],
            ],
        ];
    }
}
