<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Framework\Search\Test\Unit\Adapter\Mysql\Filter\Builder;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class WildcardTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\Search\Request\Filter\Wildcard|\PHPUnit\Framework\MockObject\MockObject
     */
    private $requestFilter;

    /**
     * @var \Magento\Framework\Search\Adapter\Mysql\Filter\Builder\Wildcard
     */
    private $filter;

    /**
     * @var \Magento\Framework\Search\Adapter\Mysql\ConditionManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private $conditionManager;

    /**
     * Set up
     */
    protected function setUp(): void
    {
        $objectManager = new ObjectManager($this);
        $this->requestFilter = $this->getMockBuilder(\Magento\Framework\Search\Request\Filter\Term::class)
            ->setMethods(['getField', 'getValue'])
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
                        return sprintf('%s %s %s', $field, $operator, $value);
                    }
                
            );

        $this->filter = $objectManager->getObject(
            \Magento\Framework\Search\Adapter\Mysql\Filter\Builder\Wildcard::class,
            [
                'conditionManager' => $this->conditionManager,
            ]
        );
    }

    /**
     * @param string $field
     * @param string $value
     * @param $isNegation
     * @param string $expectedResult
     * @dataProvider buildQueryDataProvider
     */
    public function testBuildQuery($field, $value, $isNegation, $expectedResult)
    {
        $this->requestFilter->expects($this->once())
            ->method('getField')
            ->willReturn($field);
        $this->requestFilter->expects($this->once())->method('getValue')->willReturn($value);

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
            'positive' => [
                'field' => 'testField',
                'value' => 'testValue',
                'isNegation' => false,
                'expectedResult' => "testField LIKE %testValue%",
            ],
            'negative' => [
                'field' => 'testField2',
                'value' => 'testValue2',
                'isNegation' => true,
                'expectedResult' => "testField2 NOT LIKE %testValue2%",
            ],
        ];
    }
}
