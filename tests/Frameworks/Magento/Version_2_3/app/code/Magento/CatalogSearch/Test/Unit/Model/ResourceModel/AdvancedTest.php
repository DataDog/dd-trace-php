<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\CatalogSearch\Test\Unit\Model\ResourceModel;

use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use PHPUnit\Framework\TestCase;

class AdvancedTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\CatalogSearch\Model\ResourceModel\Advanced
     */
    private $model;

    /**
     * setUp method for AdvancedTest
     */
    protected function setUp(): void
    {
        $helper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->model = $helper->getObject(\Magento\CatalogSearch\Model\ResourceModel\Advanced::class);
    }

    /**
     * @dataProvider prepareConditionDataProvider
     */
    public function testPrepareCondition($backendType, $value, $expected)
    {
        /** @var Attribute|\PHPUnit\Framework\MockObject\MockObject $attributeMock */
        $attributeMock = $this->getMockBuilder(\Magento\Catalog\Model\ResourceModel\Eav\Attribute::class)
            ->setMethods(['getBackendType'])
            ->disableOriginalConstructor()
            ->getMock();
        $attributeMock->expects($this->once())
            ->method('getBackendType')
            ->willReturn($backendType);

        $this->assertEquals(
            $expected,
            $this->model->prepareCondition($attributeMock, $value)
        );
    }

    /**
     * Data provider for testPrepareCondition
     *
     * @return array
     */
    public function prepareConditionDataProvider()
    {
        return [
            ['string', 'string', 'string'],
            ['varchar', 'string', ['like' => 'string']],
            ['varchar', ['test'], ['in_set' => ['test']]],
            ['select', ['test'], ['in' => ['test']]],
            ['range', ['from' => 1], ['from' => 1]],
            ['range', ['to' => 3], ['to' => 3]],
            ['range', ['from' => 1, 'to' => 3], ['from' => 1, 'to' => 3]]
        ];
    }
}
