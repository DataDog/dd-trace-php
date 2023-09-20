<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Test\Unit\Model\Order;

/**
 * Unit test for refund adapter.
 */
class RefundAdapterTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Sales\Model\Order\RefundAdapter
     */
    private $subject;

    /**
     * @var \Magento\Sales\Api\Data\OrderInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orderMock;

    /**
     * @var \Magento\Sales\Api\Data\CreditmemoInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $creditmemoMock;

    /**
     * @var \Magento\Sales\Model\Order\Creditmemo\RefundOperation|\PHPUnit\Framework\MockObject\MockObject
     */
    private $refundOperationMock;

    /**
     * @var \Magento\Sales\Api\Data\InvoiceInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $invoiceMock;

    protected function setUp(): void
    {
        $this->orderMock = $this->getMockBuilder(\Magento\Sales\Api\Data\OrderInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->creditmemoMock = $this->getMockBuilder(\Magento\Sales\Api\Data\CreditmemoInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->refundOperationMock = $this->getMockBuilder(\Magento\Sales\Model\Order\Creditmemo\RefundOperation::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->invoiceMock = $this->getMockBuilder(\Magento\Sales\Api\Data\InvoiceInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->subject = new \Magento\Sales\Model\Order\RefundAdapter(
            $this->refundOperationMock
        );
    }

    public function testRefund()
    {
        $isOnline = true;
        $this->refundOperationMock->expects($this->once())
            ->method('execute')
            ->with($this->creditmemoMock, $this->orderMock, $isOnline)
            ->willReturn($this->orderMock);
        $this->assertEquals(
            $this->orderMock,
            $this->subject->refund($this->creditmemoMock, $this->orderMock, $isOnline)
        );
    }
}
