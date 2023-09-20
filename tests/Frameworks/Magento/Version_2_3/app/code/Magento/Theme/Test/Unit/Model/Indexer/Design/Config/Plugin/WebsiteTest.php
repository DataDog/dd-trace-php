<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Theme\Test\Unit\Model\Indexer\Design\Config\Plugin;

use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Theme\Model\Data\Design\Config;
use Magento\Theme\Model\Indexer\Design\Config\Plugin\Website;

class WebsiteTest extends \PHPUnit\Framework\TestCase
{
    /** @var Website */
    protected $model;

    /** @var IndexerRegistry|\PHPUnit\Framework\MockObject\MockObject */
    protected $indexerRegistryMock;

    protected function setUp(): void
    {
        $this->indexerRegistryMock = $this->getMockBuilder(\Magento\Framework\Indexer\IndexerRegistry::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->model = new Website($this->indexerRegistryMock);
    }

    public function testAroundSave()
    {
        $subjectId = 0;

        /** @var \Magento\Store\Model\Website|\PHPUnit\Framework\MockObject\MockObject $subjectMock */
        $subjectMock = $this->getMockBuilder(\Magento\Store\Model\Website::class)
            ->disableOriginalConstructor()
            ->getMock();
        $subjectMock->expects($this->once())
            ->method('getId')
            ->willReturn($subjectId);

        $closureMock = function () use ($subjectMock) {
            return $subjectMock;
        };

        /** @var IndexerInterface|\PHPUnit\Framework\MockObject\MockObject $indexerMock */
        $indexerMock = $this->getMockBuilder(\Magento\Framework\Indexer\IndexerInterface::class)
            ->getMockForAbstractClass();
        $indexerMock->expects($this->once())
            ->method('invalidate');

        $this->indexerRegistryMock->expects($this->once())
            ->method('get')
            ->with(Config::DESIGN_CONFIG_GRID_INDEXER_ID)
            ->willReturn($indexerMock);

        $this->assertEquals($subjectMock, $this->model->aroundSave($subjectMock, $closureMock));
    }

    public function testAroundSaveWithExistentSubject()
    {
        $subjectId = 1;

        /** @var \Magento\Store\Model\Website|\PHPUnit\Framework\MockObject\MockObject $subjectMock */
        $subjectMock = $this->getMockBuilder(\Magento\Store\Model\Website::class)
            ->disableOriginalConstructor()
            ->getMock();
        $subjectMock->expects($this->once())
            ->method('getId')
            ->willReturn($subjectId);

        $closureMock = function () use ($subjectMock) {
            return $subjectMock;
        };

        $this->indexerRegistryMock->expects($this->never())
            ->method('get');

        $this->assertEquals($subjectMock, $this->model->aroundSave($subjectMock, $closureMock));
    }

    public function testAfterDelete()
    {
        /** @var \Magento\Store\Model\Website|\PHPUnit\Framework\MockObject\MockObject $subjectMock */
        $subjectMock = $this->getMockBuilder(\Magento\Store\Model\Website::class)
            ->disableOriginalConstructor()
            ->getMock();

        /** @var IndexerInterface|\PHPUnit\Framework\MockObject\MockObject $indexerMock */
        $indexerMock = $this->getMockBuilder(\Magento\Framework\Indexer\IndexerInterface::class)
            ->getMockForAbstractClass();
        $indexerMock->expects($this->once())
            ->method('invalidate');

        $this->indexerRegistryMock->expects($this->once())
            ->method('get')
            ->with(Config::DESIGN_CONFIG_GRID_INDEXER_ID)
            ->willReturn($indexerMock);

        $this->assertEquals($subjectMock, $this->model->afterDelete($subjectMock, $subjectMock));
    }
}
