<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Framework\Indexer\Test\Unit;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Indexer\BatchSizeManagement;
use Magento\Framework\Indexer\IndexTableRowSizeEstimatorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BatchSizeManagementTest extends TestCase
{
    /**
     * @var BatchSizeManagement
     */
    private $model;

    /**
     * @var IndexTableRowSizeEstimatorInterface|MockObject
     */
    private $rowSizeEstimatorMock;

    /**
     * @var LoggerInterface|MockObject
     */
    private $loggerMock;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->rowSizeEstimatorMock = $this->createMock(
            IndexTableRowSizeEstimatorInterface::class
        );
        $this->loggerMock = $this->getMockForAbstractClass(LoggerInterface::class);
        $this->model = new BatchSizeManagement($this->rowSizeEstimatorMock, $this->loggerMock);
    }

    /**
     * @return void
     */
    public function testEnsureBatchSize(): void
    {
        $batchSize = 200;
        $maxHeapTableSize = 16384;
        $tmpTableSize = 16384;
        $size = 20000;
        $innodbPollSize = 100;

        $this->rowSizeEstimatorMock->expects($this->once())->method('estimateRowSize')->willReturn(100);
        $adapterMock = $this->getMockForAbstractClass(AdapterInterface::class);

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(__(
                "Memory size allocated for the temporary table is more than 20% of innodb_buffer_pool_size. " .
                "Please update innodb_buffer_pool_size or decrease batch size value " .
                "(which decreases memory usages for the temporary table). " .
                "Current batch size: %1; Allocated memory size: %2 bytes; InnoDB buffer pool size: %3 bytes.",
                [$batchSize, $size, $innodbPollSize]
            ));

        $adapterMock
            ->method('fetchOne')
            ->withConsecutive(
                ['SELECT @@max_heap_table_size;', []],
                ['SELECT @@tmp_table_size;', []],
                ['SELECT @@innodb_buffer_pool_size;', []]
            )
            ->willReturnOnConsecutiveCalls($maxHeapTableSize, $tmpTableSize, $innodbPollSize);
        $adapterMock
            ->method('query')
            ->withConsecutive(
                ['SET SESSION tmp_table_size = ' . $size . ';', []],
                ['SET SESSION max_heap_table_size = ' . $size . ';', []]
            );

        $this->model->ensureBatchSize($adapterMock, $batchSize);
    }
}
