<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Sales\Test\Unit\Model\Order\Creditmemo\Total;

/**
 * Class SubtotalTest
 */
class SubtotalTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Sales\Model\Order\Creditmemo\Total\Subtotal
     */
    protected $total;

    /**
     * @var \Magento\Sales\Model\Order\Creditmemo|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $creditmemoMock;

    /**
     * @var \Magento\Sales\Model\Order\Creditmemo\Item|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $creditmemoItemMock;

    /**
     * @var \Magento\Sales\Model\Order\Item|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $orderItemMock;

    protected function setUp(): void
    {
        $this->orderMock = $this->createPartialMock(
            \Magento\Sales\Model\Order::class,
            ['getBaseShippingDiscountAmount', 'getBaseShippingAmount', 'getShippingAmount']
        );
        $this->orderItemMock = $this->createPartialMock(\Magento\Sales\Model\Order::class, [
                'isDummy', 'getDiscountInvoiced', 'getBaseDiscountInvoiced', 'getQtyInvoiced', 'getQty',
                'getDiscountRefunded', 'getQtyRefunded'
            ]);
        $this->creditmemoMock = $this->createPartialMock(\Magento\Sales\Model\Order\Creditmemo::class, [
                'setBaseCost', 'getAllItems', 'getOrder', 'getBaseShippingAmount', 'roundPrice',
                'setDiscountAmount', 'setBaseDiscountAmount', 'setSubtotal', 'setBaseSubtotal',
                'setSubtotalInclTax', 'setBaseSubtotalInclTax', 'getGrandTotal', 'setGrandTotal',
                'getBaseGrandTotal', 'setBaseGrandTotal'
            ]);
        $this->creditmemoItemMock = $this->createPartialMock(\Magento\Sales\Model\Order\Creditmemo\Item::class, [
                'getHasChildren', 'getBaseCost', 'getQty', 'getOrderItem', 'setDiscountAmount',
                'setBaseDiscountAmount', 'isLast', 'getRowTotalInclTax', 'getBaseRowTotalInclTax',
                'getRowTotal', 'getBaseRowTotal', 'calcRowTotal'
            ]);
        $this->total = new \Magento\Sales\Model\Order\Creditmemo\Total\Subtotal();
    }

    public function testCollect()
    {
        $this->creditmemoMock->expects($this->once())
            ->method('getAllItems')
            ->willReturn([$this->creditmemoItemMock]);
        $this->creditmemoItemMock->expects($this->atLeastOnce())
            ->method('getOrderItem')
            ->willReturn($this->orderItemMock);
        $this->orderItemMock->expects($this->once())
            ->method('isDummy')
            ->willReturn(false);
        $this->creditmemoItemMock->expects($this->once())
            ->method('calcRowTotal')
            ->willReturnSelf();
        $this->creditmemoItemMock->expects($this->once())
            ->method('getRowTotal')
            ->willReturn(1);
        $this->creditmemoItemMock->expects($this->once())
            ->method('getBaseRowTotal')
            ->willReturn(1);
        $this->creditmemoItemMock->expects($this->once())
            ->method('getRowTotalInclTax')
            ->willReturn(1);
        $this->creditmemoItemMock->expects($this->once())
            ->method('getBaseRowTotalInclTax')
            ->willReturn(1);
        $this->creditmemoMock->expects($this->once())
            ->method('setSubtotal')
            ->with(1)
            ->willReturnSelf();
        $this->creditmemoMock->expects($this->once())
            ->method('setBaseSubtotal')
            ->with(1)
            ->willReturnSelf();
        $this->creditmemoMock->expects($this->once())
            ->method('setSubtotalInclTax')
            ->with(1)
            ->willReturnSelf();
        $this->creditmemoMock->expects($this->once())
            ->method('setBaseSubtotalInclTax')
            ->with(1)
            ->willReturnSelf();
        $this->creditmemoMock->expects($this->once())
            ->method('getGrandTotal')
            ->willReturn(1);
        $this->creditmemoMock->expects($this->once())
            ->method('setGrandTotal')
            ->with(2)
            ->willReturnSelf();
        $this->creditmemoMock->expects($this->once())
            ->method('getBaseGrandTotal')
            ->willReturn(1);
        $this->creditmemoMock->expects($this->once())
            ->method('setBaseGrandTotal')
            ->with(2)
            ->willReturnSelf();
        $this->assertEquals($this->total, $this->total->collect($this->creditmemoMock));
    }
}
