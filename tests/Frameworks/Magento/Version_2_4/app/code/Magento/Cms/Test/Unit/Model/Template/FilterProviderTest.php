<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Cms\Test\Unit\Model\Template;

use Magento\Cms\Model\Template\Filter;
use Magento\Cms\Model\Template\FilterProvider;
use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FilterProviderTest extends TestCase
{
    /**
     * @var FilterProvider
     */
    protected $_model;

    /**
     * @var MockObject
     */
    protected $_objectManagerMock;

    /**
     * @var MockObject
     */
    protected $_filterMock;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->_filterMock = $this->createMock(Filter::class);
        $this->_objectManagerMock = $this->getMockForAbstractClass(ObjectManagerInterface::class);
        $this->_objectManagerMock->expects($this->any())->method('get')->willReturn($this->_filterMock);
        $this->_model = new FilterProvider($this->_objectManagerMock);
    }

    /**
     * @return void
     * @covers \Magento\Cms\Model\Template\FilterProvider::getBlockFilter
     */
    public function testGetBlockFilter(): void
    {
        $this->assertInstanceOf(Filter::class, $this->_model->getBlockFilter());
    }

    /**
     * @return void
     * @covers \Magento\Cms\Model\Template\FilterProvider::getPageFilter
     */
    public function testGetPageFilter(): void
    {
        $this->assertInstanceOf(Filter::class, $this->_model->getPageFilter());
    }

    /**
     * @return void
     * @covers \Magento\Cms\Model\Template\FilterProvider::getPageFilter
     */
    public function testGetPageFilterInnerCache(): void
    {
        $this->_objectManagerMock->expects($this->once())->method('get')->willReturn($this->_filterMock);
        $this->_model->getPageFilter();
        $this->_model->getPageFilter();
    }

    /**
     * @return void
     * @covers \Magento\Cms\Model\Template\FilterProvider::getPageFilter
     */
    public function testGetPageWrongInstance(): void
    {
        $this->expectException('Exception');
        $someClassMock = $this->getMockBuilder('SomeClass')
            ->allowMockingUnknownTypes()
            ->getMock();
        $objectManagerMock = $this->getMockForAbstractClass(ObjectManagerInterface::class);
        $objectManagerMock->expects($this->once())->method('get')->willReturn($someClassMock);
        $model = new FilterProvider($objectManagerMock, 'SomeClass', 'SomeClass');
        $model->getPageFilter();
    }
}
