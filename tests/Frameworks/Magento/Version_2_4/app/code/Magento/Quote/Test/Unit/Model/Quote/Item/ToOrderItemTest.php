<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Quote\Test\Unit\Model\Quote\Item;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\Simple;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\DataObject\Copy;
use Magento\Quote\Model\Quote\Item;
use Magento\Quote\Model\Quote\Item\ToOrderItem;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\OrderItemInterfaceFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ToOrderItemTest extends TestCase
{
    /**
     * @var ToOrderItem
     */
    protected $converter;

    /**
     * @var OrderItemInterfaceFactory|MockObject
     */
    protected $orderItemFactoryMock;

    /**
     * @var Copy|MockObject
     */
    protected $objectCopyServiceMock;

    /**
     * @var Item|MockObject
     */
    protected $quoteItemMock;

    /**
     * @var Product|MockObject
     */
    protected $productMock;

    /**
     * @var Simple|MockObject
     */
    protected $productTypeMock;

    /**
     * @var OrderItemInterface|MockObject
     */
    protected $orderItemMock;

    /**
     * @inheriDoc
     */
    protected function setUp(): void
    {
        $this->orderItemFactoryMock = $this->createPartialMock(
            OrderItemInterfaceFactory::class,
            ['create']
        );
        $this->objectCopyServiceMock = $this->createMock(Copy::class);
        $this->quoteItemMock = $this->createMock(Item::class);
        $this->productMock = $this->createMock(Product::class);
        $this->productTypeMock = $this->createMock(Simple::class);
        $this->orderItemMock = $this->createMock(\Magento\Sales\Model\Order\Item::class);
        $dataObjectHelper = $this->createMock(DataObjectHelper::class);

        $this->converter = new ToOrderItem(
            $this->orderItemFactoryMock,
            $this->objectCopyServiceMock,
            $dataObjectHelper
        );
    }

    /**
     * test for convert method
     *
     * @return void
     */
    public function testConvert(): void
    {
        $this->quoteItemMock->expects($this->exactly(2))
            ->method('getProduct')
            ->willReturn($this->productMock);
        $this->productMock->expects($this->once())
            ->method('getTypeInstance')
            ->willReturn($this->productTypeMock);
        $this->productTypeMock->expects($this->once())
            ->method('getOrderOptions')
            ->with($this->productMock)
            ->willReturn(['option']);
        $this->objectCopyServiceMock
            ->method('getDataFromFieldset')
            ->withConsecutive(
                ['quote_convert_item', 'to_order_item', $this->quoteItemMock],
                ['quote_convert_item', 'to_order_item_discount', $this->quoteItemMock]
            )
            ->willReturnOnConsecutiveCalls([], []);
        $this->orderItemFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($this->orderItemMock);
        $this->assertInstanceOf(
            \Magento\Sales\Model\Order\Item::class,
            $this->converter->convert($this->quoteItemMock, [])
        );
    }
}
