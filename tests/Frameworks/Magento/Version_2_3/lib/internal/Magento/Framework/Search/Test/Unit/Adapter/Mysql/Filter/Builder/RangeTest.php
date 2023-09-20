<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Search\Test\Unit\Adapter\Mysql\Filter\Builder;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class RangeTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Search\Request\Filter\Term|\PHPUnit\Framework\MockObject\MockObject
     */
    private $requestFilter;

    /**
     * @var \Magento\Framework\Search\Adapter\Mysql\Filter\Builder\Range
     */
    private $filter;

    /**
     * @var \Magento\Framework\Search\Adapter\Mysql\ConditionManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private $conditionManager;

    /**
     * Set Up
     */
    protected function setUp(): void
    {
        $objectManager = new ObjectManager($this);
        $this->requestFilter = $this->getMockBuilder(\Magento\Framework\Search\Request\Filter\Range::class)
            ->setMethods(['getField', 'getFrom', 'getTo'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->conditionManager = $this->getMockBuilder(\Magento\Framework\Search\Adapter\Mysql\ConditionManager::class)
            ->disableOriginalConstructor()
            ->setMethods(['generateCondition'])
            ->getMock();
        $this->conditionManager->expects($this->any())
            ->method('generateCondition')
            ->willReturnCallback(
                
                    function ($field, $operator, $value) {
                        return sprintf('%s %s \'%s\'', $field, $operator, $value);
                    }
                
            );

        $this->filter = $objectManager->getObject(
            \Magento\Framework\Search\Adapter\Mysql\Filter\Builder\Range::class,
            [
                'conditionManager' => $this->conditionManager,
            ]
        );
    }

    /**
     * @param string $field
     * @param string $from
     * @param string $to
     * @param bool $isNegation
     * @param string $expectedResult
     * @dataProvider buildQueryDataProvider
     */
    public function testBuildQuery($field, $from, $to, $isNegation, $expectedResult)
    {
        $this->requestFilter->expects($this->any())
            ->method('getField')
            ->willReturn($field);
        $this->requestFilter->expects($this->atLeastOnce())
            ->method('getFrom')
            ->willReturn($from);
        $this->requestFilter->expects($this->atLeastOnce())
            ->method('getTo')
            ->willReturn($to);

        $actualResult = $this->filter->buildFilter($this->requestFilter, $isNegation);
        $this->assertEquals($expectedResult, $actualResult);
    }

    /**
     * Data provider for BuildQuery
     *
     * @return array
     */
    public function buildQueryDataProvider()
    {
        return [
            'rangeWithStrings' => [
                'field' => 'testField',
                'from' => '0',
                'to' => '10',
                'isNegation' => false,
                'expectedResult' => 'testField >= \'0\' AND testField <= \'10\'',
            ],
            'rangeWithIntegers' => [
                'field' => 'testField',
                'from' => 50,
                'to' => 50,
                'isNegation' => false,
                'expectedResult' => 'testField >= \'50\' AND testField <= \'50\'',
            ],
            'rangeWithFloats' => [
                'field' => 'testField',
                'from' => 50.5,
                'to' => 55.5,
                'isNegation' => false,
                'expectedResult' => 'testField >= \'50.5\' AND testField <= \'55.5\'',
            ],
            'rangeWithStringsNegative' => [
                'field' => 'testField',
                'from' => '0',
                'to' => '10',
                'isNegation' => true,
                'expectedResult' => 'testField < \'0\' OR testField > \'10\'',
            ],
            'rangeWithoutFromValue' => [
                'field' => 'testField',
                'from' => null,
                'to' => 50,
                'isNegation' => false,
                'expectedResult' => 'testField <= \'50\'',
            ],
            'rangeWithoutFromValueNegative' => [
                'field' => 'testField',
                'from' => null,
                'to' => 50,
                'isNegation' => true,
                'expectedResult' => 'testField > \'50\'',
            ],
            'rangeWithoutToValue' => [
                'field' => 'testField',
                'from' => 50,
                'to' => null,
                'isNegation' => false,
                'expectedResult' => 'testField >= \'50\'',
            ],
            'rangeWithoutToValueNegative' => [
                'field' => 'testField',
                'from' => 50,
                'to' => null,
                'isNegation' => true,
                'expectedResult' => 'testField < \'50\'',
            ],
            'rangeWithEmptyValues' => [
                'field' => 'testField',
                'from' => null,
                'to' => null,
                'isNegation' => false,
                'expectedResult' => '',
            ],
            'rangeWithEmptyValuesNegative' => [
                'field' => 'testField',
                'from' => null,
                'to' => null,
                'isNegation' => true,
                'expectedResult' => '',
            ],
        ];
    }
}
