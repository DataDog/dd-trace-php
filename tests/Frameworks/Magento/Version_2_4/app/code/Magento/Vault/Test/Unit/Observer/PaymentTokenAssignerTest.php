<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Vault\Test\Unit\Observer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Model\Quote\Payment;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Magento\Vault\Observer\PaymentTokenAssigner;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PaymentTokenAssignerTest extends TestCase
{
    /**
     * @var PaymentTokenManagementInterface|MockObject
     */
    private $paymentTokenManagement;

    /**
     * @var PaymentTokenAssigner
     */
    private $observer;

    protected function setUp(): void
    {
        $this->paymentTokenManagement = $this->getMockForAbstractClass(PaymentTokenManagementInterface::class);
        $this->observer = new PaymentTokenAssigner($this->paymentTokenManagement);
    }

    public function testExecuteNoPublicHash()
    {
        $dataObject = new DataObject();
        $observer = $this->getPreparedObserverWithMap(
            [
                [AbstractDataAssignObserver::DATA_CODE, $dataObject]
            ]
        );

        $this->paymentTokenManagement->expects(static::never())
            ->method('getByPublicHash');
        $this->observer->execute($observer);
    }

    public function testExecuteNotOrderPaymentModel()
    {
        $dataObject = new DataObject(
            [
                PaymentInterface::KEY_ADDITIONAL_DATA => [
                    PaymentTokenInterface::PUBLIC_HASH => 'public_hash_value'
                ]
            ]
        );
        $paymentModel = $this->getMockForAbstractClass(InfoInterface::class);

        $observer = $this->getPreparedObserverWithMap(
            [
                [AbstractDataAssignObserver::DATA_CODE, $dataObject],
                [AbstractDataAssignObserver::MODEL_CODE, $paymentModel]
            ]
        );

        $this->paymentTokenManagement->expects(static::never())
            ->method('getByPublicHash');
        $this->observer->execute($observer);
    }

    public function testExecuteNoPaymentToken()
    {
        $customerId = 1;
        $publicHash = 'public_hash_value';
        $dataObject = new DataObject(
            [
                PaymentInterface::KEY_ADDITIONAL_DATA => [
                    PaymentTokenInterface::PUBLIC_HASH => $publicHash
                ]
            ]
        );

        $paymentModel = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->getMock();
        $quote = $this->getMockForAbstractClass(CartInterface::class);
        $customer = $this->getMockForAbstractClass(CustomerInterface::class);

        $paymentModel->expects(static::once())
            ->method('getQuote')
            ->willReturn($quote);
        $quote->expects(static::once())
            ->method('getCustomer')
            ->willReturn($customer);
        $customer->expects(static::once())
            ->method('getId')
            ->willReturn($customerId);

        $this->paymentTokenManagement->expects(static::once())
            ->method('getByPublicHash')
            ->with($publicHash, $customerId)
            ->willReturn(null);

        $observer = $this->getPreparedObserverWithMap(
            [
                [AbstractDataAssignObserver::DATA_CODE, $dataObject],
                [AbstractDataAssignObserver::MODEL_CODE, $paymentModel]
            ]
        );

        $paymentModel->expects(static::never())
            ->method('setAdditionalInformation');

        $this->observer->execute($observer);
    }

    public function testExecuteSaveMetadata()
    {
        $customerId = 1;
        $publicHash = 'public_hash_value';
        $dataObject = new DataObject(
            [
                PaymentInterface::KEY_ADDITIONAL_DATA => [
                    PaymentTokenInterface::PUBLIC_HASH => $publicHash
                ]
            ]
        );

        $paymentModel = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->getMock();
        $quote = $this->getMockForAbstractClass(CartInterface::class);
        $customer = $this->getMockForAbstractClass(CustomerInterface::class);
        $paymentToken = $this->getMockForAbstractClass(PaymentTokenInterface::class);

        $paymentModel->expects(static::once())
            ->method('getQuote')
            ->willReturn($quote);
        $quote->expects(static::once())
            ->method('getCustomer')
            ->willReturn($customer);
        $customer->expects(static::once())
            ->method('getId')
            ->willReturn($customerId);

        $this->paymentTokenManagement->expects(static::once())
            ->method('getByPublicHash')
            ->with($publicHash, $customerId)
            ->willReturn($paymentToken);

        $paymentModel->expects(static::once())
            ->method('setAdditionalInformation')
            ->with(
                [
                    PaymentTokenInterface::CUSTOMER_ID => $customerId,
                    PaymentTokenInterface::PUBLIC_HASH => $publicHash
                ]
            );

        $observer = $this->getPreparedObserverWithMap(
            [
                [AbstractDataAssignObserver::DATA_CODE, $dataObject],
                [AbstractDataAssignObserver::MODEL_CODE, $paymentModel]
            ]
        );

        $this->observer->execute($observer);
    }

    /**
     * @param array $returnMap
     * @return MockObject|Observer
     */
    private function getPreparedObserverWithMap(array $returnMap)
    {
        $observer = $this->getMockBuilder(Observer::class)
            ->disableOriginalConstructor()
            ->getMock();
        $event = $this->getMockBuilder(Event::class)
            ->disableOriginalConstructor()
            ->getMock();

        $observer->expects(static::atLeastOnce())
            ->method('getEvent')
            ->willReturn($event);
        $event->expects(static::atLeastOnce())
            ->method('getDataByKey')
            ->willReturnMap(
                $returnMap
            );

        return $observer;
    }
}
