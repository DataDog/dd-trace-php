<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Reports\Test\Unit\Model\ResourceModel\Quote;

class CollectionTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Reports\Model\ResourceModel\Quote\Collection
     */
    protected $model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $customerResourceMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $connectionMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $resourceMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $selectMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $fetchStrategyMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $entityFactoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject | \Magento\Framework\Model\ResourceModel\Db\VersionControl\Snapshot
     */
    protected $entitySnapshotMock;

    protected function setUp(): void
    {
        $this->selectMock = $this->getMockBuilder(\Magento\Framework\DB\Select::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->selectMock->expects($this->any())
            ->method('from')
            ->withAnyParameters()
            ->willReturnSelf();

        $this->connectionMock = $this->getMockBuilder(\Magento\Framework\DB\Adapter\Pdo\Mysql::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->connectionMock->expects($this->any())
            ->method('select')
            ->willReturn($this->selectMock);
        $this->resourceMock = $this->getMockBuilder(\Magento\Quote\Model\ResourceModel\Quote::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->resourceMock->expects($this->any())
            ->method('getConnection')
            ->willReturn($this->connectionMock);
        $this->resourceMock->expects($this->any())
            ->method('getMainTable')
            ->willReturn('test_table');
        $this->resourceMock->expects($this->any())
            ->method('getTable')
            ->willReturn('test_table');
        $this->customerResourceMock = $this->getMockBuilder(\Magento\Customer\Model\ResourceModel\Customer::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->fetchStrategyMock = $this->getMockBuilder(
            \Magento\Framework\Data\Collection\Db\FetchStrategy\Query::class
        )->disableOriginalConstructor()->getMock();

        $this->entityFactoryMock = $this->getMockBuilder(\Magento\Framework\Data\Collection\EntityFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $snapshotClassName = \Magento\Framework\Model\ResourceModel\Db\VersionControl\Snapshot::class;
        $this->entitySnapshotMock = $this->getMockBuilder($snapshotClassName)
            ->disableOriginalConstructor()
            ->setMethods(['registerSnapshot'])
            ->getMock();

        $helper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->model = $helper->getObject(
            \Magento\Reports\Model\ResourceModel\Quote\Collection::class,
            [
                'customerResource' => $this->customerResourceMock,
                'resource' => $this->resourceMock,
                'fetchStrategy' => $this->fetchStrategyMock,
                'entityFactory' => $this->entityFactoryMock,
                'entitySnapshot' => $this->entitySnapshotMock
            ]
        );
    }

    public function testResolveCustomerNames()
    {
        $customerName = "CONCAT_WS('firstname', 'lastname')";
        $customerTableName = 'customer_entity';
        $customerId = ['customer_id' => ['test_id']];
        $customersData = [['entity_id' => 'test_id', 'name' => 'item_1']];

        $this->selectMock->expects($this->any())
            ->method('getConnection')
            ->willReturn($this->connectionMock);
        $this->selectMock->expects($this->once())
            ->method('from')
            ->with(['customer' => $customerTableName], ['entity_id', 'email'])
            ->willReturnSelf();
        $this->selectMock->expects($this->once())
            ->method('columns')
            ->with(['customer_name' => $customerName])
            ->willReturnSelf();
        $this->selectMock->expects($this->once())
            ->method('where')
            ->with('customer.entity_id IN (?)')
            ->willReturnSelf();

        $this->connectionMock->expects($this->once())
            ->method('getConcatSql')
            ->with(['firstname', 'lastname'], ' ')
            ->willReturn($customerName);

        $this->customerResourceMock->expects($this->any())
            ->method('getConnection')
            ->willReturn($this->connectionMock);
        $this->customerResourceMock->expects($this->once())
            ->method('getTable')
            ->with('customer_entity')
            ->willReturn($customerTableName);

        $this->connectionMock->expects($this->any())
            ->method('select')
            ->willReturn($this->selectMock);
        $this->connectionMock->expects($this->once())
            ->method('fetchAll')
            ->with($this->selectMock)
            ->willReturn($customersData);

        $this->fetchStrategyMock->expects($this->once())
            ->method('fetchAll')
            ->withAnyParameters()
            ->willReturn($customerId);

        $itemMock = $this->getMockBuilder(\Magento\Framework\Model\AbstractModel::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->entityFactoryMock->expects($this->any())
            ->method('create')
            ->willReturn($itemMock);

        $this->assertNull($this->model->resolveCustomerNames());
    }
}
