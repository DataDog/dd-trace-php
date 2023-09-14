<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Sales\Test\Unit\Model\Order\Creditmemo;

use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\Data\CreditmemoItemInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\RefundOperation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for refund operation.
 */
class RefundOperationTest extends TestCase
{
    /**
     * @var RefundOperation
     */
    private $subject;

    /**
     * @var OrderInterface|MockObject
     */
    private $orderMock;

    /**
     * @var CreditmemoInterface|MockObject
     */
    private $creditmemoMock;

    /**
     * @var OrderPaymentInterface|MockObject
     */
    private $paymentMock;

    /**
     * @var PriceCurrencyInterface|MockObject
     */
    private $priceCurrencyMock;

    /**
     * @var ManagerInterface|MockObject
     */
    private $eventManagerMock;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->orderMock = $this->getMockBuilder(OrderInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->creditmemoMock = $this->getMockBuilder(CreditmemoInterface::class)
            ->disableOriginalConstructor()
            ->addMethods(['getBaseCost', 'setDoTransaction', 'getPaymentRefundDisallowed'])
            ->getMockForAbstractClass();

        $this->paymentMock = $this->getMockBuilder(PriceCurrencyInterface::class)
            ->disableOriginalConstructor()
            ->addMethods(['refund'])
            ->getMockForAbstractClass();

        $this->priceCurrencyMock = $this->getMockBuilder(PriceCurrencyInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['round'])
            ->getMockForAbstractClass();

        $contextMock = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEventDispatcher'])
            ->getMock();

        $this->eventManagerMock = $this->getMockBuilder(ManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $contextMock->expects($this->once())
            ->method('getEventDispatcher')
            ->willReturn($this->eventManagerMock);

        $this->subject = new RefundOperation(
            $contextMock,
            $this->priceCurrencyMock
        );
    }

    /**
     * @param int $state
     *
     * @return void
     * @dataProvider  executeNotRefundedCreditmemoDataProvider
     */
    public function testExecuteNotRefundedCreditmemo(int $state): void
    {
        $this->creditmemoMock->expects($this->once())
            ->method('getState')
            ->willReturn($state);
        $this->orderMock->expects($this->never())
            ->method('getEntityId');
        $this->assertEquals(
            $this->orderMock,
            $this->subject->execute(
                $this->creditmemoMock,
                $this->orderMock
            )
        );
    }

    /**
     * Data provider for testExecuteNotRefundedCreditmemo
     *
     * @return array
     */
    public function executeNotRefundedCreditmemoDataProvider(): array
    {
        return [
            [Creditmemo::STATE_OPEN],
            [Creditmemo::STATE_CANCELED]
        ];
    }

    /**
     * @return void
     */
    public function testExecuteWithWrongOrder(): void
    {
        $creditmemoOrderId = 1;
        $orderId = 2;
        $this->creditmemoMock->expects($this->once())
            ->method('getState')
            ->willReturn(Creditmemo::STATE_REFUNDED);
        $this->creditmemoMock->expects($this->once())
            ->method('getOrderId')
            ->willReturn($creditmemoOrderId);
        $this->orderMock->expects($this->once())
            ->method('getEntityId')
            ->willReturn($orderId);
        $this->orderMock->expects($this->never())
            ->method('setTotalRefunded');
        $this->assertEquals(
            $this->orderMock,
            $this->subject->execute($this->creditmemoMock, $this->orderMock)
        );
    }

    /**
     * @param array $amounts
     * @return void
     *
     * @dataProvider baseAmountsDataProvider
     */
    public function testExecuteOffline(array $amounts): void
    {
        $orderId = 1;
        $online = false;
        $this->creditmemoMock->expects($this->once())
            ->method('getState')
            ->willReturn(Creditmemo::STATE_REFUNDED);
        $this->creditmemoMock->expects($this->once())
            ->method('getOrderId')
            ->willReturn($orderId);
        $this->orderMock->expects($this->once())
            ->method('getEntityId')
            ->willReturn($orderId);

        $this->registerItems();

        $this->priceCurrencyMock->expects($this->any())
            ->method('round')
            ->willReturnArgument(0);

        $this->setBaseAmounts($amounts);
        $this->orderMock->expects($this->once())
            ->method('setTotalOfflineRefunded')
            ->with(2);
        $this->orderMock->expects($this->once())
            ->method('getTotalOfflineRefunded')
            ->willReturn(0);
        $this->orderMock->expects($this->once())
            ->method('setBaseTotalOfflineRefunded')
            ->with(1);
        $this->orderMock->expects($this->once())
            ->method('getBaseTotalOfflineRefunded')
            ->willReturn(0);
        $this->orderMock->expects($this->never())
            ->method('setTotalOnlineRefunded');

        $this->orderMock->expects($this->once())
            ->method('getPayment')
            ->willReturn($this->paymentMock);

        $this->paymentMock->expects($this->once())
            ->method('refund')
            ->with($this->creditmemoMock);

        $this->creditmemoMock->expects($this->once())
            ->method('setDoTransaction')
            ->with($online);

        $this->eventManagerMock->expects($this->once())
            ->method('dispatch')
            ->with(
                'sales_order_creditmemo_refund',
                ['creditmemo' => $this->creditmemoMock]
            );

        $this->assertEquals(
            $this->orderMock,
            $this->subject->execute($this->creditmemoMock, $this->orderMock, $online)
        );
    }

    /**
     * @param array $amounts
     *
     * @return void
     * @dataProvider baseAmountsDataProvider
     */
    public function testExecuteOnline(array $amounts): void
    {
        $orderId = 1;
        $online = true;
        $this->creditmemoMock->expects($this->once())
            ->method('getState')
            ->willReturn(Creditmemo::STATE_REFUNDED);
        $this->creditmemoMock->expects($this->once())
            ->method('getOrderId')
            ->willReturn($orderId);
        $this->orderMock->expects($this->once())
            ->method('getEntityId')
            ->willReturn($orderId);

        $this->registerItems();

        $this->priceCurrencyMock->expects($this->any())
            ->method('round')
            ->willReturnArgument(0);

        $this->setBaseAmounts($amounts);
        $this->orderMock->expects($this->once())
            ->method('setTotalOnlineRefunded')
            ->with(2);
        $this->orderMock->expects($this->once())
            ->method('getTotalOnlineRefunded')
            ->willReturn(0);
        $this->orderMock->expects($this->once())
            ->method('setBaseTotalOnlineRefunded')
            ->with(1);
        $this->orderMock->expects($this->once())
            ->method('getBaseTotalOnlineRefunded')
            ->willReturn(0);
        $this->orderMock->expects($this->never())
            ->method('setTotalOfflineRefunded');

        $this->creditmemoMock->expects($this->once())
            ->method('setDoTransaction')
            ->with($online);

        $this->orderMock->expects($this->once())
            ->method('getPayment')
            ->willReturn($this->paymentMock);
        $this->paymentMock->expects($this->once())
            ->method('refund')
            ->with($this->creditmemoMock);

        $this->assertEquals(
            $this->orderMock,
            $this->subject->execute($this->creditmemoMock, $this->orderMock, $online)
        );
    }

    /**
     * @return array
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function baseAmountsDataProvider(): array
    {
        return [
            [[
                'setBaseTotalRefunded' => [
                    'result' => 2,
                    'order' => ['method' => 'getBaseTotalRefunded', 'amount' => 1],
                    'creditmemo' => ['method' => 'getBaseGrandTotal', 'amount' => 1],
                ],
                'setTotalRefunded' => [
                    'result' => 4,
                    'order' => ['method' => 'getTotalRefunded', 'amount' => 2],
                    'creditmemo' => ['method' => 'getGrandTotal', 'amount' => 2],
                ],
                'setBaseSubtotalRefunded' => [
                    'result' => 6,
                    'order' => ['method' => 'getBaseSubtotalRefunded', 'amount' => 3],
                    'creditmemo' => ['method' => 'getBaseSubtotal', 'amount' => 3],
                ],
                'setSubtotalRefunded' => [
                    'result' => 6,
                    'order' => ['method' => 'getSubtotalRefunded', 'amount' => 3],
                    'creditmemo' => ['method' => 'getSubtotal', 'amount' => 3],
                ],
                'setBaseTaxRefunded' => [
                    'result' => 8,
                    'order' => ['method' => 'getBaseTaxRefunded', 'amount' => 4],
                    'creditmemo' => ['method' => 'getBaseTaxAmount', 'amount' => 4],
                ],
                'setTaxRefunded' => [
                    'result' => 10,
                    'order' => ['method' => 'getTaxRefunded', 'amount' => 5],
                    'creditmemo' => ['method' => 'getTaxAmount', 'amount' => 5],
                ],
                'setBaseDiscountTaxCompensationRefunded' => [
                    'result' => 12,
                    'order' => ['method' => 'getBaseDiscountTaxCompensationRefunded', 'amount' => 6],
                    'creditmemo' => ['method' => 'getBaseDiscountTaxCompensationAmount', 'amount' => 6],
                ],
                'setDiscountTaxCompensationRefunded' => [
                    'result' => 14,
                    'order' => ['method' => 'getDiscountTaxCompensationRefunded', 'amount' => 7],
                    'creditmemo' => ['method' => 'getDiscountTaxCompensationAmount', 'amount' => 7],
                ],
                'setBaseShippingRefunded' => [
                    'result' => 16,
                    'order' => ['method' => 'getBaseShippingRefunded', 'amount' => 8],
                    'creditmemo' => ['method' => 'getBaseShippingAmount', 'amount' => 8],
                ],
                'setShippingRefunded' => [
                    'result' => 18,
                    'order' => ['method' => 'getShippingRefunded', 'amount' => 9],
                    'creditmemo' => ['method' => 'getShippingAmount', 'amount' => 9],
                ],
                'setBaseShippingTaxRefunded' => [
                    'result' => 20,
                    'order' => ['method' => 'getBaseShippingTaxRefunded', 'amount' => 10],
                    'creditmemo' => ['method' => 'getBaseShippingTaxAmount', 'amount' => 10],
                ],
                'setShippingTaxRefunded' => [
                    'result' => 22,
                    'order' => ['method' => 'getShippingTaxRefunded', 'amount' => 11],
                    'creditmemo' => ['method' => 'getShippingTaxAmount', 'amount' => 11],
                ],
                'setAdjustmentPositive' => [
                    'result' => 24,
                    'order' => ['method' => 'getAdjustmentPositive', 'amount' => 12],
                    'creditmemo' => ['method' => 'getAdjustmentPositive', 'amount' => 12],
                ],
                'setBaseAdjustmentPositive' => [
                    'result' => 26,
                    'order' => ['method' => 'getBaseAdjustmentPositive', 'amount' => 13],
                    'creditmemo' => ['method' => 'getBaseAdjustmentPositive', 'amount' => 13],
                ],
                'setAdjustmentNegative' => [
                    'result' => 28,
                    'order' => ['method' => 'getAdjustmentNegative', 'amount' => 14],
                    'creditmemo' => ['method' => 'getAdjustmentNegative', 'amount' => 14],
                ],
                'setBaseAdjustmentNegative' => [
                    'result' => 30,
                    'order' => ['method' => 'getBaseAdjustmentNegative', 'amount' => 15],
                    'creditmemo' => ['method' => 'getBaseAdjustmentNegative', 'amount' => 15],
                ],
                'setDiscountRefunded' => [
                    'result' => 32,
                    'order' => ['method' => 'getDiscountRefunded', 'amount' => 16],
                    'creditmemo' => ['method' => 'getDiscountAmount', 'amount' => 16],
                ],
                'setBaseDiscountRefunded' => [
                    'result' => 34,
                    'order' => ['method' => 'getBaseDiscountRefunded', 'amount' => 17],
                    'creditmemo' => ['method' => 'getBaseDiscountAmount', 'amount' => 17],
                ],
                'setBaseTotalInvoicedCost' => [
                    'result' => 7,
                    'order' => ['method' => 'getBaseTotalInvoicedCost', 'amount' => 18],
                    'creditmemo' => ['method' => 'getBaseCost', 'amount' => 11],
                ]
            ]]
        ];
    }

    /**
     * @param array $amounts
     *
     * @return void
     */
    private function setBaseAmounts(array $amounts): void
    {
        foreach ($amounts as $amountName => $summands) {
            $this->orderMock->expects($this->once())
                ->method($amountName)
                ->with($summands['result']);
            $this->orderMock->expects($this->once())
                ->method($summands['order']['method'])
                ->willReturn($summands['order']['amount']);
            $this->creditmemoMock->expects($this->any())
                ->method($summands['creditmemo']['method'])
                ->willReturn($summands['creditmemo']['amount']);
        }
    }

    /**
     * @return void
     */
    private function registerItems(): void
    {
        $item1 = $this->getCreditmemoItemMock();
        $item1->expects($this->once())->method('isDeleted')->willReturn(true);
        $item1->expects($this->never())->method('setCreditMemo');

        $item2 = $this->getCreditmemoItemMock();
        $item2->expects($this->once())->method('setCreditMemo')->with($this->creditmemoMock);
        $item2->expects($this->once())->method('getQty')->willReturn(0);
        $item2
            ->method('isDeleted')
            ->withConsecutive([], [true])
            ->willReturnOnConsecutiveCalls(false, null);

        $item2->expects($this->never())->method('register');

        $item3 = $this->getCreditmemoItemMock();
        $item3->expects($this->once())->method('isDeleted')->willReturn(false);
        $item3->expects($this->once())->method('setCreditMemo')->with($this->creditmemoMock);
        $item3->expects($this->once())->method('getQty')->willReturn(1);
        $item3->expects($this->once())->method('register');

        $this->creditmemoMock->expects($this->any())
            ->method('getItems')
            ->willReturn([$item1, $item2, $item3]);
    }

    /**
     * @return MockObject
     */
    private function getCreditmemoItemMock(): MockObject
    {
        return $this->getMockBuilder(CreditmemoItemInterface::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQty'])
            ->addMethods(['isDeleted', 'setCreditMemo', 'register'])
            ->getMockForAbstractClass();
    }
}
