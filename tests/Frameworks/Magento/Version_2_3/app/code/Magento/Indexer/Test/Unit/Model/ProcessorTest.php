<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Indexer\Test\Unit\Model;

use Magento\Framework\Indexer\IndexerInterfaceFactory;
use Magento\Framework\Indexer\StateInterface;

class ProcessorTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Indexer\Model\Processor|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $model;

    /**
     * @var \Magento\Framework\Indexer\ConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $configMock;

    /**
     * @var IndexerInterfaceFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $indexerFactoryMock;

    /**
     * @var \Magento\Indexer\Model\Indexer\CollectionFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $indexersFactoryMock;

    /**
     * @var \Magento\Framework\Mview\ProcessorInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $viewProcessorMock;

    protected function setUp(): void
    {
        $this->configMock = $this->getMockForAbstractClass(
            \Magento\Framework\Indexer\ConfigInterface::class,
            [],
            '',
            false,
            false,
            true,
            ['getIndexers']
        );
        $this->indexerFactoryMock = $this->createPartialMock(
            IndexerInterfaceFactory::class,
            ['create']
        );
        $this->indexersFactoryMock = $this->createPartialMock(
            \Magento\Indexer\Model\Indexer\CollectionFactory::class,
            ['create']
        );
        $this->viewProcessorMock = $this->getMockForAbstractClass(
            \Magento\Framework\Mview\ProcessorInterface::class,
            [],
            '',
            false
        );
        $this->model = new \Magento\Indexer\Model\Processor(
            $this->configMock,
            $this->indexerFactoryMock,
            $this->indexersFactoryMock,
            $this->viewProcessorMock
        );
    }

    public function testReindexAllInvalid()
    {
        $indexers = ['indexer1' => [], 'indexer2' => []];

        $this->configMock->expects($this->once())->method('getIndexers')->willReturn($indexers);

        $state1Mock = $this->createPartialMock(\Magento\Indexer\Model\Indexer\State::class, ['getStatus', '__wakeup']);
        $state1Mock->expects(
            $this->once()
        )->method(
            'getStatus'
        )->willReturn(
            StateInterface::STATUS_INVALID
        );
        $indexer1Mock = $this->createPartialMock(
            \Magento\Indexer\Model\Indexer::class,
            ['load', 'getState', 'reindexAll']
        );
        $indexer1Mock->expects($this->once())->method('getState')->willReturn($state1Mock);
        $indexer1Mock->expects($this->once())->method('reindexAll');

        $state2Mock = $this->createPartialMock(\Magento\Indexer\Model\Indexer\State::class, ['getStatus', '__wakeup']);
        $state2Mock->expects(
            $this->once()
        )->method(
            'getStatus'
        )->willReturn(
            StateInterface::STATUS_VALID
        );
        $indexer2Mock = $this->createPartialMock(
            \Magento\Indexer\Model\Indexer::class,
            ['load', 'getState', 'reindexAll']
        );
        $indexer2Mock->expects($this->never())->method('reindexAll');
        $indexer2Mock->expects($this->once())->method('getState')->willReturn($state2Mock);

        $this->indexerFactoryMock->expects($this->at(0))->method('create')->willReturn($indexer1Mock);
        $this->indexerFactoryMock->expects($this->at(1))->method('create')->willReturn($indexer2Mock);

        $this->model->reindexAllInvalid();
    }

    public function testReindexAll()
    {
        $indexerMock = $this->createMock(\Magento\Indexer\Model\Indexer::class);
        $indexerMock->expects($this->exactly(2))->method('reindexAll');
        $indexers = [$indexerMock, $indexerMock];

        $indexersMock = $this->createMock(\Magento\Indexer\Model\Indexer\Collection::class);
        $this->indexersFactoryMock->expects($this->once())->method('create')->willReturn($indexersMock);
        $indexersMock->expects($this->once())->method('getItems')->willReturn($indexers);

        $this->model->reindexAll();
    }

    public function testUpdateMview()
    {
        $this->viewProcessorMock->expects($this->once())->method('update')->with('indexer')->willReturnSelf();
        $this->model->updateMview();
    }

    public function testClearChangelog()
    {
        $this->viewProcessorMock->expects($this->once())->method('clearChangelog')->with('indexer')->willReturnSelf();
        $this->model->clearChangelog();
    }
}
