<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Reports\Test\Unit\Model\ResourceModel\Event;

use Magento\Reports\Model\ResourceModel\Event\Collection;

class CollectionTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Reports\Model\ResourceModel\Event\Collection
     */
    protected $collection;

    /**
     * @var \Magento\Framework\Data\Collection\EntityFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $entityFactoryMock;

    /**
     * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $loggerMock;

    /**
     * @var \Magento\Framework\Data\Collection\Db\FetchStrategyInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $fetchStrategyMock;

    /**
     * @var \Magento\Framework\Event\ManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $managerMock;

    /**
     * @var \Magento\Framework\Model\ResourceModel\Db\AbstractDb|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resourceMock;

    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $dbMock;

    /**
     * @var \Magento\Framework\DB\Select|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $selectMock;

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void
    {
        $this->entityFactoryMock = $this->getMockBuilder(
            \Magento\Framework\Data\Collection\EntityFactoryInterface::class
        )->getMock();
        $this->loggerMock = $this->getMockBuilder(\Psr\Log\LoggerInterface::class)
            ->getMock();
        $this->fetchStrategyMock = $this->getMockBuilder(
            \Magento\Framework\Data\Collection\Db\FetchStrategyInterface::class
        )->getMock();
        $this->managerMock = $this->getMockBuilder(\Magento\Framework\Event\ManagerInterface::class)
            ->getMock();

        $this->selectMock = $this->getMockBuilder(\Magento\Framework\DB\Select::class)
            ->setMethods(['where', 'from'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->selectMock->expects($this->any())
            ->method('from')
            ->willReturnSelf();
        $this->selectMock->expects($this->any())
            ->method('where')
            ->willReturnSelf();

        $this->dbMock = $this->getMockBuilder(\Magento\Framework\DB\Adapter\Pdo\Mysql::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->dbMock->expects($this->any())
            ->method('select')
            ->willReturn($this->selectMock);

        $this->resourceMock = $this->getMockBuilder(\Magento\Framework\Model\ResourceModel\Db\AbstractDb::class)
            ->disableOriginalConstructor()
            ->setMethods(['getConnection', 'getCurrentStoreIds', '_construct', 'getMainTable', 'getTable'])
            ->getMock();
        $this->resourceMock->expects($this->any())
            ->method('getConnection')
            ->willReturn($this->dbMock);

        $this->collection = new Collection(
            $this->entityFactoryMock,
            $this->loggerMock,
            $this->fetchStrategyMock,
            $this->managerMock,
            null,
            $this->resourceMock
        );
    }

    /**
     * @param mixed $ignoreData
     * @param 'string' $ignoreSql
     * @dataProvider ignoresDataProvider
     * @return void
     */
    public function testAddStoreFilter($ignoreData, $ignoreSql)
    {
        $typeId = 1;
        $subjectId =2;
        $subtype = 3;
        $limit = 0;
        $stores = [1, 2];

        $this->resourceMock
            ->expects($this->once())
            ->method('getCurrentStoreIds')
            ->willReturn($stores);
        $this->selectMock
            ->expects($this->at(0))
            ->method('where')
            ->with('event_type_id = ?', $typeId);
        $this->selectMock
            ->expects($this->at(1))
            ->method('where')
            ->with('subject_id = ?', $subjectId);
        $this->selectMock
            ->expects($this->at(2))
            ->method('where')
            ->with('subtype = ?', $subtype);
        $this->selectMock
            ->expects($this->at(3))
            ->method('where')
            ->with('store_id IN(?)', $stores);
        $this->selectMock
            ->expects($this->at(4))
            ->method('where')
            ->with($ignoreSql, $ignoreData);

        $this->collection->addRecentlyFiler($typeId, $subjectId, $subtype, $ignoreData, $limit);
    }

    /**
     * @return array
     */
    public function ignoresDataProvider()
    {
        return [
            [
                'ignoreData' => 1,
                'ignoreSql' => 'object_id <> ?'
            ],
            [
                'ignoreData' => [1],
                'ignoreSql' => 'object_id NOT IN(?)'
            ]
        ];
    }
}
