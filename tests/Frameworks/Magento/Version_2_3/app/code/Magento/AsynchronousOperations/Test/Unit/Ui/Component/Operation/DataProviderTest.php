<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\AsynchronousOperations\Test\Unit\Ui\Component\Operation;

use Magento\AsynchronousOperations\Ui\Component\Operation\DataProvider;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

class DataProviderTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var DataProvider
     */
    private $dataProvider;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $bulkCollectionFactoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $bulkCollectionMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $operationDetailsMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $requestMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $bulkMock;

    /**
     * Set up
     *
     * @return void
     */
    protected function setUp(): void
    {
        $helper = new ObjectManager($this);

        $this->bulkCollectionFactoryMock = $this->createPartialMock(
            \Magento\AsynchronousOperations\Model\ResourceModel\Bulk\CollectionFactory::class,
            ['create']
        );
        $this->bulkCollectionMock = $this->createMock(
            \Magento\AsynchronousOperations\Model\ResourceModel\Bulk\Collection::class
        );
        $this->operationDetailsMock = $this->createMock(\Magento\AsynchronousOperations\Model\Operation\Details::class);
        $this->bulkMock = $this->createMock(\Magento\AsynchronousOperations\Model\BulkSummary::class);
        $this->requestMock = $this->createMock(\Magento\Framework\App\RequestInterface::class);

        $this->bulkCollectionFactoryMock
            ->expects($this->once())
            ->method('create')
            ->willReturn($this->bulkCollectionMock);

        $this->dataProvider = $helper->getObject(
            \Magento\AsynchronousOperations\Ui\Component\Operation\DataProvider::class,
            [
                'name' => 'test-name',
                'bulkCollectionFactory' => $this->bulkCollectionFactoryMock,
                'operationDetails' => $this->operationDetailsMock,
                'request' => $this->requestMock
            ]
        );
    }

    public function testGetData()
    {
        $testData = [
            'id' => '1',
            'uuid' => 'bulk-uuid1',
            'user_id' => '2',
            'description' => 'Description'
        ];
        $testOperationData = [
            'operations_total' => 2,
            'operations_successful' => 1,
            'operations_failed' => 2
        ];
        $testSummaryData = [
            'summary' => '2 items selected for mass update, 1 successfully updated, 2 failed to update'
        ];
        $resultData[$testData['id']] = array_merge($testData, $testOperationData, $testSummaryData);

        $this->bulkCollectionMock
            ->expects($this->once())
            ->method('getItems')
            ->willReturn([$this->bulkMock]);
        $this->bulkMock
            ->expects($this->once())
            ->method('getData')
            ->willReturn($testData);
        $this->operationDetailsMock
            ->expects($this->once())
            ->method('getDetails')
            ->with($testData['uuid'])
            ->willReturn($testOperationData);
        $this->bulkMock
            ->expects($this->once())
            ->method('getBulkId')
            ->willReturn($testData['id']);

        $expectedResult = $this->dataProvider->getData();
        $this->assertEquals($resultData, $expectedResult);
    }

    public function testPrepareMeta()
    {
        $resultData['retriable_operations']['arguments']['data']['disabled'] = true;
        $resultData['failed_operations']['arguments']['data']['disabled'] = true;
        $testData = [
            'uuid' => 'bulk-uuid1',
            'failed_retriable' => 0,
            'failed_not_retriable' => 0
        ];

        $this->requestMock
            ->expects($this->once())
            ->method('getParam')
            ->willReturn($testData['uuid']);
        $this->operationDetailsMock
            ->expects($this->once())
            ->method('getDetails')
            ->with($testData['uuid'])
            ->willReturn($testData);

        $expectedResult = $this->dataProvider->prepareMeta([]);
        $this->assertEquals($resultData, $expectedResult);
    }
}
