<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Test\Unit\Model\ResourceModel\Order\Tax;

/**
 * Class ItemTest
 */
class ItemTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $connectionMock;

    /**
     * @var \Magento\Framework\App\ResourceConnection|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $appResourceMock;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\Tax\Item
     */
    protected $taxItem;

    /**
     * Initialization
     */
    protected function setUp(): void
    {
        $this->connectionMock = $this->createMock(\Magento\Framework\DB\Adapter\Pdo\Mysql::class);
        $this->appResourceMock = $this->createMock(\Magento\Framework\App\ResourceConnection::class);
        $this->appResourceMock->expects($this->any())
            ->method('getConnection')
            ->willReturn($this->connectionMock);
        $this->appResourceMock->expects($this->any())->method('getTableName')->willReturnArgument(0);
        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->taxItem = $objectManager->getObject(
            \Magento\Sales\Model\ResourceModel\Order\Tax\Item::class,
            [
                'resource' => $this->appResourceMock
            ]
        );
    }

    public function testGetTaxItemsByOrderId()
    {
        $orderId = 1;
        $taxItems = [
            [
                'tax_id' => 1,
                'tax_percent' => 5,
                'item_id' => 1,
                'taxable_item_type' => 4,
                'associated_item_id' => 1,
                'real_amount' => 12,
                'real_base_amount' => 12
            ]
        ];
        $select = $this->createMock(\Magento\Framework\DB\Select::class);
        $this->connectionMock->expects($this->once())->method('select')->willReturn($select);
        $select->expects($this->once())->method('from')->with(
            ['item' => 'sales_order_tax_item'],
            [
                'tax_id',
                'tax_percent',
                'item_id',
                'taxable_item_type',
                'associated_item_id',
                'real_amount',
                'real_base_amount',
            ]
        )->willReturnSelf();
        $select->expects($this->once())->method('join')->with(
            ['tax' => 'sales_order_tax'],
            'item.tax_id = tax.tax_id',
            ['code', 'title', 'order_id']
        )->willReturnSelf();
        $select->expects($this->once())->method('where')->with(
            'tax.order_id = ?',
            $orderId
        )->willReturnSelf();
        $this->connectionMock->expects($this->once())->method('fetchAll')->with($select)->willReturn($taxItems);
        $this->assertEquals($taxItems, $this->taxItem->getTaxItemsByOrderId($orderId));
    }
}
