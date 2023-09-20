<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Sales\Test\Unit\Model\Service;

use Magento\Sales\Model\Order;

use Magento\Sales\Api\Data\CreditmemoInterface;
use PHPUnit\Framework\MockObject\MockObject as MockObject;

/**
 * Class CreditmemoServiceTest
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CreditmemoServiceTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Sales\Api\CreditmemoRepositoryInterface|MockObject
     */
    protected $creditmemoRepositoryMock;

    /**
     * @var \Magento\Sales\Api\CreditmemoCommentRepositoryInterface|MockObject
     */
    protected $creditmemoCommentRepositoryMock;

    /**
     * @var \Magento\Framework\Api\SearchCriteriaBuilder|MockObject
     */
    protected $searchCriteriaBuilderMock;

    /**
     * @var \Magento\Framework\Api\FilterBuilder|MockObject
     */
    protected $filterBuilderMock;

    /**
     * @var \Magento\Sales\Model\Order\CreditmemoNotifier|MockObject
     */
    protected $creditmemoNotifierMock;

    /**
     * @var \Magento\Framework\Pricing\PriceCurrencyInterface|MockObject
     */
    private $priceCurrency;

    /**
     * @var \Magento\Sales\Model\Service\CreditmemoService
     */
    protected $creditmemoService;

    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    private $objectManagerHelper;

    /**
     * SetUp
     */
    protected function setUp(): void
    {
        $this->creditmemoRepositoryMock = $this->getMockForAbstractClass(
            \Magento\Sales\Api\CreditmemoRepositoryInterface::class,
            ['get'],
            '',
            false
        );
        $this->creditmemoCommentRepositoryMock = $this->getMockForAbstractClass(
            \Magento\Sales\Api\CreditmemoCommentRepositoryInterface::class,
            [],
            '',
            false
        );
        $this->searchCriteriaBuilderMock = $this->createPartialMock(
            \Magento\Framework\Api\SearchCriteriaBuilder::class,
            ['create', 'addFilters']
        );
        $this->filterBuilderMock = $this->createPartialMock(
            \Magento\Framework\Api\FilterBuilder::class,
            ['setField', 'setValue', 'setConditionType', 'create']
        );
        $this->creditmemoNotifierMock = $this->createMock(\Magento\Sales\Model\Order\CreditmemoNotifier::class);
        $this->priceCurrency = $this->getMockBuilder(\Magento\Framework\Pricing\PriceCurrencyInterface::class)
            ->getMockForAbstractClass();
        $this->objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->creditmemoService = $this->objectManagerHelper->getObject(
            \Magento\Sales\Model\Service\CreditmemoService::class,
            [
                'creditmemoRepository' => $this->creditmemoRepositoryMock,
                'creditmemoCommentRepository' => $this->creditmemoCommentRepositoryMock,
                'searchCriteriaBuilder' => $this->searchCriteriaBuilderMock,
                'filterBuilder' => $this->filterBuilderMock,
                'creditmemoNotifier' => $this->creditmemoNotifierMock,
                'priceCurrency' => $this->priceCurrency,
            ]
        );
    }

    /**
     * Run test cancel method
     */
    public function testCancel()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('You can not cancel Credit Memo');

        $this->assertTrue($this->creditmemoService->cancel(1));
    }

    /**
     * Run test getCommentsList method
     */
    public function testGetCommentsList()
    {
        $id = 25;
        $returnValue = 'return-value';

        $filterMock = $this->createMock(\Magento\Framework\Api\Filter::class);
        $searchCriteriaMock = $this->createMock(\Magento\Framework\Api\SearchCriteria::class);

        $this->filterBuilderMock->expects($this->once())
            ->method('setField')
            ->with('parent_id')
            ->willReturnSelf();
        $this->filterBuilderMock->expects($this->once())
            ->method('setValue')
            ->with($id)
            ->willReturnSelf();
        $this->filterBuilderMock->expects($this->once())
            ->method('setConditionType')
            ->with('eq')
            ->willReturnSelf();
        $this->filterBuilderMock->expects($this->once())
            ->method('create')
            ->willReturn($filterMock);
        $this->searchCriteriaBuilderMock->expects($this->once())
            ->method('addFilters')
            ->with([$filterMock]);
        $this->searchCriteriaBuilderMock->expects($this->once())
            ->method('create')
            ->willReturn($searchCriteriaMock);
        $this->creditmemoCommentRepositoryMock->expects($this->once())
            ->method('getList')
            ->with($searchCriteriaMock)
            ->willReturn($returnValue);

        $this->assertEquals($returnValue, $this->creditmemoService->getCommentsList($id));
    }

    /**
     * Run test notify method
     */
    public function testNotify()
    {
        $id = 123;
        $returnValue = 'return-value';

        $modelMock = $this->getMockForAbstractClass(
            \Magento\Sales\Model\AbstractModel::class,
            [],
            '',
            false
        );

        $this->creditmemoRepositoryMock->expects($this->once())
            ->method('get')
            ->with($id)
            ->willReturn($modelMock);
        $this->creditmemoNotifierMock->expects($this->once())
            ->method('notify')
            ->with($modelMock)
        ->willReturn($returnValue);

        $this->assertEquals($returnValue, $this->creditmemoService->notify($id));
    }

    public function testRefund()
    {
        $creditMemoMock = $this->getMockBuilder(\Magento\Sales\Api\Data\CreditmemoInterface::class)
            ->setMethods(['getId', 'getOrder', 'getInvoice'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $creditMemoMock->expects($this->once())->method('getId')->willReturn(null);
        $orderMock = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->getMock();

        $creditMemoMock->expects($this->atLeastOnce())->method('getOrder')->willReturn($orderMock);
        $orderMock->expects($this->once())->method('getBaseTotalRefunded')->willReturn(0);
        $orderMock->expects($this->once())->method('getBaseTotalPaid')->willReturn(10);
        $creditMemoMock->expects($this->once())->method('getBaseGrandTotal')->willReturn(10);

        $this->priceCurrency->expects($this->any())
            ->method('round')
            ->willReturnArgument(0);

        // Set payment adapter dependency
        $refundAdapterMock = $this->getMockBuilder(\Magento\Sales\Model\Order\RefundAdapterInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->objectManagerHelper->setBackwardCompatibleProperty(
            $this->creditmemoService,
            'refundAdapter',
            $refundAdapterMock
        );

        // Set resource dependency
        $resourceMock = $this->getMockBuilder(\Magento\Framework\App\ResourceConnection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->objectManagerHelper->setBackwardCompatibleProperty(
            $this->creditmemoService,
            'resource',
            $resourceMock
        );

        // Set order repository dependency
        $orderRepositoryMock = $this->getMockBuilder(\Magento\Sales\Api\OrderRepositoryInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->objectManagerHelper->setBackwardCompatibleProperty(
            $this->creditmemoService,
            'orderRepository',
            $orderRepositoryMock
        );

        $adapterMock = $this->getMockBuilder(\Magento\Framework\DB\Adapter\AdapterInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $resourceMock->expects($this->once())->method('getConnection')->with('sales')->willReturn($adapterMock);
        $adapterMock->expects($this->once())->method('beginTransaction');
        $refundAdapterMock->expects($this->once())
            ->method('refund')
            ->with($creditMemoMock, $orderMock, false)
            ->willReturn($orderMock);
        $orderRepositoryMock->expects($this->once())
            ->method('save')
            ->with($orderMock);
        $creditMemoMock->expects($this->once())
            ->method('getInvoice')
            ->willReturn(null);
        $adapterMock->expects($this->once())->method('commit');
        $this->creditmemoRepositoryMock->expects($this->once())
            ->method('save');

        $this->assertSame($creditMemoMock, $this->creditmemoService->refund($creditMemoMock, true));
    }

    public function testRefundPendingCreditMemo()
    {
        $creditMemoMock = $this->getMockBuilder(\Magento\Sales\Api\Data\CreditmemoInterface::class)
            ->setMethods(['getId', 'getOrder', 'getState', 'getInvoice'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $creditMemoMock->expects($this->once())->method('getId')->willReturn(444);
        $creditMemoMock->expects($this->once())->method('getState')
            ->willReturn(\Magento\Sales\Model\Order\Creditmemo::STATE_OPEN);
        $orderMock = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->getMock();

        $creditMemoMock->expects($this->atLeastOnce())->method('getOrder')->willReturn($orderMock);
        $orderMock->expects($this->once())->method('getBaseTotalRefunded')->willReturn(0);
        $orderMock->expects($this->once())->method('getBaseTotalPaid')->willReturn(10);
        $creditMemoMock->expects($this->once())->method('getBaseGrandTotal')->willReturn(10);

        $this->priceCurrency->expects($this->any())
            ->method('round')
            ->willReturnArgument(0);

        // Set payment adapter dependency
        $refundAdapterMock = $this->getMockBuilder(\Magento\Sales\Model\Order\RefundAdapterInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->objectManagerHelper->setBackwardCompatibleProperty(
            $this->creditmemoService,
            'refundAdapter',
            $refundAdapterMock
        );

        // Set resource dependency
        $resourceMock = $this->getMockBuilder(\Magento\Framework\App\ResourceConnection::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->objectManagerHelper->setBackwardCompatibleProperty(
            $this->creditmemoService,
            'resource',
            $resourceMock
        );

        // Set order repository dependency
        $orderRepositoryMock = $this->getMockBuilder(\Magento\Sales\Api\OrderRepositoryInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $this->objectManagerHelper->setBackwardCompatibleProperty(
            $this->creditmemoService,
            'orderRepository',
            $orderRepositoryMock
        );

        $adapterMock = $this->getMockBuilder(\Magento\Framework\DB\Adapter\AdapterInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $resourceMock->expects($this->once())->method('getConnection')->with('sales')->willReturn($adapterMock);
        $adapterMock->expects($this->once())->method('beginTransaction');
        $refundAdapterMock->expects($this->once())
            ->method('refund')
            ->with($creditMemoMock, $orderMock, false)
            ->willReturn($orderMock);
        $orderRepositoryMock->expects($this->once())
            ->method('save')
            ->with($orderMock);
        $creditMemoMock->expects($this->once())
            ->method('getInvoice')
            ->willReturn(null);
        $adapterMock->expects($this->once())->method('commit');
        $this->creditmemoRepositoryMock->expects($this->once())
            ->method('save');

        $this->assertSame($creditMemoMock, $this->creditmemoService->refund($creditMemoMock, true));
    }

    /**
     */
    public function testRefundExpectsMoneyAvailableToReturn()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('The most money available to refund is 1.');

        $baseGrandTotal = 10;
        $baseTotalRefunded = 9;
        $baseTotalPaid = 10;
        /** @var CreditmemoInterface|MockObject $creditMemo */
        $creditMemo = $this->getMockBuilder(CreditmemoInterface::class)
            ->setMethods(['getId', 'getOrder'])
            ->getMockForAbstractClass();
        $creditMemo->method('getId')
            ->willReturn(null);
        /** @var Order|MockObject $order */
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        $creditMemo->method('getOrder')
            ->willReturn($order);
        $creditMemo->method('getBaseGrandTotal')
            ->willReturn($baseGrandTotal);
        $order->method('getBaseTotalRefunded')
            ->willReturn($baseTotalRefunded);
        $this->priceCurrency->method('round')
            ->withConsecutive([$baseTotalRefunded + $baseGrandTotal], [$baseTotalPaid])
            ->willReturnOnConsecutiveCalls($baseTotalRefunded + $baseGrandTotal, $baseTotalPaid);
        $order->method('getBaseTotalPaid')
            ->willReturn($baseTotalPaid);
        $baseAvailableRefund = $baseTotalPaid - $baseTotalRefunded;
        $baseCurrency = $this->createMock(\Magento\Directory\Model\Currency::class);
        $baseCurrency->expects($this->once())
            ->method('formatTxt')
            ->with($baseAvailableRefund)
            ->willReturn($baseAvailableRefund);
        $order->expects($this->once())
            ->method('getBaseCurrency')
            ->willReturn($baseCurrency);
        $this->creditmemoService->refund($creditMemo, true);
    }

    /**
     */
    public function testRefundDoNotExpectsId()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('We cannot register an existing credit memo.');

        $creditMemoMock = $this->getMockBuilder(\Magento\Sales\Api\Data\CreditmemoInterface::class)
            ->setMethods(['getId'])
            ->getMockForAbstractClass();
        $creditMemoMock->expects($this->once())->method('getId')->willReturn(444);
        $this->creditmemoService->refund($creditMemoMock, true);
    }

    /**
     */
    public function testMultiCurrencyRefundExpectsMoneyAvailableToReturn()
    {
        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);
        $this->expectExceptionMessage('The most money available to refund is $1.00.');

        $baseGrandTotal = 10.00;
        $baseTotalRefunded = 9.00;
        $baseTotalPaid = 10;
        $grandTotal = 8.81;
        $totalRefunded = 7.929;
        $totalPaid = 8.81;

        /** @var CreditmemoInterface|MockObject $creditMemo */
        $creditMemo = $this->getMockBuilder(CreditmemoInterface::class)
            ->setMethods(['getId', 'getOrder'])
            ->getMockForAbstractClass();
        $creditMemo->method('getId')
            ->willReturn(null);
        /** @var Order|MockObject $order */
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        $creditMemo->method('getOrder')
            ->willReturn($order);
        $creditMemo->method('getBaseGrandTotal')
            ->willReturn($baseGrandTotal);
        $creditMemo->method('getGrandTotal')
            ->willReturn($grandTotal);
        $order->method('getBaseTotalRefunded')
            ->willReturn($baseTotalRefunded);
        $order->method('getTotalRefunded')
            ->willReturn($totalRefunded);
        $this->priceCurrency->method('round')
            ->withConsecutive([$baseTotalRefunded + $baseGrandTotal], [$baseTotalPaid])
            ->willReturnOnConsecutiveCalls($baseTotalRefunded + $baseGrandTotal, $baseTotalPaid);
        $order->method('getBaseTotalPaid')
            ->willReturn($baseTotalPaid);
        $order->method('getTotalPaid')
            ->willReturn($totalPaid);
        $baseAvailableRefund = $baseTotalPaid - $baseTotalRefunded;
        $baseCurrency = $this->createMock(\Magento\Directory\Model\Currency::class);
        $baseCurrency->expects($this->once())
            ->method('formatTxt')
            ->with($baseAvailableRefund)
            ->willReturn(sprintf('$%.2f', $baseAvailableRefund));
        $order->expects($this->once())
            ->method('getBaseCurrency')
            ->willReturn($baseCurrency);
        $this->creditmemoService->refund($creditMemo, true);
    }
}
