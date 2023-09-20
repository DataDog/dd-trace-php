<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Model\Indexer\Category\Flat\Plugin;

use Magento\Catalog\Model\Indexer\Category\Flat\Plugin\StoreView;

class StoreViewTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|\Magento\Framework\Indexer\IndexerInterface
     */
    protected $indexerMock;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|\Magento\Catalog\Model\Indexer\Category\Flat\State
     */
    protected $stateMock;

    /**
     * @var StoreView
     */
    protected $model;

    /**
     * @var \Magento\Framework\Indexer\IndexerRegistry|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $indexerRegistryMock;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $subjectMock;

    protected function setUp()
    {
        $this->indexerMock = $this->getMockForAbstractClass(
            \Magento\Framework\Indexer\IndexerInterface::class,
            [],
            '',
            false,
            false,
            true,
            ['getId', 'getState', '__wakeup']
        );
        $this->stateMock = $this->createPartialMock(
            \Magento\Catalog\Model\Indexer\Category\Flat\State::class,
            ['isFlatEnabled']
        );
        $this->subjectMock = $this->createMock(\Magento\Store\Model\ResourceModel\Store::class);
        $this->indexerRegistryMock = $this->createPartialMock(
            \Magento\Framework\Indexer\IndexerRegistry::class,
            ['get']
        );
        $this->model = new StoreView($this->indexerRegistryMock, $this->stateMock);
    }

    public function testBeforeAndAfterSaveNewObject()
    {
        $this->mockConfigFlatEnabled();
        $this->mockIndexerMethods();
        $storeMock = $this->createPartialMock(
            \Magento\Store\Model\Store::class,
            ['isObjectNew', 'dataHasChangedFor', '__wakeup']
        );
        $storeMock->expects($this->once())->method('isObjectNew')->will($this->returnValue(true));
        $this->model->beforeSave($this->subjectMock, $storeMock);
        $this->assertSame(
            $this->subjectMock,
            $this->model->afterSave($this->subjectMock, $this->subjectMock, $storeMock)
        );
    }

    public function testBeforeAndAfterSaveHasChanged()
    {
        $storeMock = $this->createPartialMock(
            \Magento\Store\Model\Store::class,
            ['isObjectNew', 'dataHasChangedFor', '__wakeup']
        );
        $this->model->beforeSave($this->subjectMock, $storeMock);
        $this->assertSame(
            $this->subjectMock,
            $this->model->afterSave($this->subjectMock, $this->subjectMock, $storeMock)
        );
    }

    public function testBeforeAndAfterSaveNoNeed()
    {
        $this->mockConfigFlatEnabledNeever();
        $storeMock = $this->createPartialMock(
            \Magento\Store\Model\Store::class,
            ['isObjectNew', 'dataHasChangedFor', '__wakeup']
        );
        $this->model->beforeSave($this->subjectMock, $storeMock);
        $this->assertSame(
            $this->subjectMock,
            $this->model->afterSave($this->subjectMock, $this->subjectMock, $storeMock)
        );
    }

    protected function mockIndexerMethods()
    {
        $this->indexerMock->expects($this->once())->method('invalidate');
        $this->indexerRegistryMock->expects($this->once())
            ->method('get')
            ->with(\Magento\Catalog\Model\Indexer\Category\Flat\State::INDEXER_ID)
            ->will($this->returnValue($this->indexerMock));
    }

    protected function mockConfigFlatEnabled()
    {
        $this->stateMock->expects($this->once())->method('isFlatEnabled')->will($this->returnValue(true));
    }

    protected function mockConfigFlatEnabledNeever()
    {
        $this->stateMock->expects($this->never())->method('isFlatEnabled');
    }
}
