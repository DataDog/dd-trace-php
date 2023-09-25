<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Signifyd\Test\Unit\Model\Guarantee;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Signifyd\Api\Data\CaseInterface;
use Magento\Signifyd\Model\CaseEntity;
use Magento\Signifyd\Model\CaseManagement;
use Magento\Signifyd\Model\Guarantee\CancelGuaranteeAbility;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CancelGuaranteeAbilityTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var OrderRepositoryInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orderRepository;

    /**
     * @var CaseManagement|\PHPUnit\Framework\MockObject\MockObject
     */
    private $caseManagement;

    /**
     * @var CancelGuaranteeAbility
     */
    private $cancelGuaranteeAbility;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->orderRepository = $this->getMockBuilder(OrderRepositoryInterface::class)
            ->getMockForAbstractClass();

        $this->caseManagement = $this->getMockBuilder(CaseManagement::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->cancelGuaranteeAbility = new CancelGuaranteeAbility(
            $this->caseManagement,
            $this->orderRepository
        );
    }

    /**
     * Success test for Cancel Guarantee Request button
     */
    public function testIsAvailableSuccess()
    {
        $orderId = 123;

        /** @var CaseInterface|\PHPUnit\Framework\MockObject\MockObject $case */
        $case = $this->getMockBuilder(CaseInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $case->expects($this->once())
            ->method('getGuaranteeDisposition')
            ->willReturn(CaseEntity::GUARANTEE_APPROVED);

        $this->caseManagement->expects($this->once())
            ->method('getByOrderId')
            ->with($orderId)
            ->willReturn($case);

        /** @var OrderInterface|\PHPUnit\Framework\MockObject\MockObject $order */
        $order = $this->getMockBuilder(OrderInterface::class)
            ->getMockForAbstractClass();

        $this->orderRepository->expects($this->once())
            ->method('get')
            ->with($orderId)
            ->willReturn($order);

        $this->assertTrue($this->cancelGuaranteeAbility->isAvailable($orderId));
    }

    /**
     * Tests case when Case entity doesn't exist for order
     */
    public function testIsAvailableWithNullCase()
    {
        $orderId = 123;

        $this->caseManagement->expects($this->once())
            ->method('getByOrderId')
            ->with($orderId)
            ->willReturn(null);

        $this->assertFalse($this->cancelGuaranteeAbility->isAvailable($orderId));
    }

    /**
     * Tests case when Guarantee Disposition has Canceled states.
     */
    public function testIsAvailableWithCanceledGuarantee()
    {
        $orderId = 123;

        /** @var CaseInterface|\PHPUnit\Framework\MockObject\MockObject $case */
        $case = $this->getMockBuilder(CaseInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $case->expects($this->once())
            ->method('getGuaranteeDisposition')
            ->willReturn(CaseEntity::GUARANTEE_CANCELED);

        $this->caseManagement->expects($this->once())
            ->method('getByOrderId')
            ->with($orderId)
            ->willReturn($case);

        $this->assertFalse($this->cancelGuaranteeAbility->isAvailable($orderId));
    }

    /**
     * Tests case when order does not exist.
     */
    public function testIsAvailableWithNullOrder()
    {
        $orderId = 123;

        /** @var CaseInterface|\PHPUnit\Framework\MockObject\MockObject $case */
        $case = $this->getMockBuilder(CaseInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $case->expects($this->once())
            ->method('getGuaranteeDisposition')
            ->willReturn(CaseEntity::GUARANTEE_APPROVED);

        $this->caseManagement->expects($this->once())
            ->method('getByOrderId')
            ->with($orderId)
            ->willReturn($case);

        $this->orderRepository->expects($this->once())
            ->method('get')
            ->with($orderId)
            ->willThrowException(new NoSuchEntityException());

        $this->assertFalse($this->cancelGuaranteeAbility->isAvailable($orderId));
    }
}
