<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Quote\Test\Unit\Model\Quote\Payment;

use Magento\Payment\Model\Method\Substitution;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * Class ToOrderPaymentTest tests converter to order payment
 */
class ToOrderPaymentTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Sales\Api\OrderPaymentRepositoryInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $orderPaymentRepositoryMock;

    /**
     * @var \Magento\Framework\DataObject\Copy | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $objectCopyMock;

    /**
     * @var \Magento\Quote\Model\Quote\Payment | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $paymentMock;

    /**
     * @var \Magento\Framework\Api\DataObjectHelper
     */
    protected $dataObjectHelper;

    /**
     * @var \Magento\Quote\Model\Quote\Payment\ToOrderPayment
     */
    protected $converter;

    protected function setUp(): void
    {
        $this->paymentMock = $this->createPartialMock(
            \Magento\Quote\Model\Quote\Payment::class,
            ['getCcNumber', 'getCcCid', 'getMethodInstance', 'getAdditionalInformation']
        );
        $this->objectCopyMock = $this->createMock(\Magento\Framework\DataObject\Copy::class);
        $this->orderPaymentRepositoryMock = $this->getMockForAbstractClass(
            \Magento\Sales\Api\OrderPaymentRepositoryInterface::class,
            [],
            '',
            false,
            false
        );
        $this->dataObjectHelper = $this->createMock(\Magento\Framework\Api\DataObjectHelper::class);
        $objectManager = new ObjectManager($this);
        $this->converter = $objectManager->getObject(
            \Magento\Quote\Model\Quote\Payment\ToOrderPayment::class,
            [
                'orderPaymentRepository' => $this->orderPaymentRepositoryMock,
                'objectCopyService' => $this->objectCopyMock,
                'dataObjectHelper' => $this->dataObjectHelper
            ]
        );
    }

    /**
     * Tests Convert method in payment to order payment converter
     */
    public function testConvert()
    {
        $methodInterface = $this->getMockForAbstractClass(\Magento\Payment\Model\MethodInterface::class);

        $paymentData = ['test' => 'test2'];
        $data = ['some_id' => 1];
        $paymentMethodTitle = 'TestTitle';
        $additionalInfo = ['token' => 'TOKEN-123'];

        $this->paymentMock->expects($this->once())->method('getMethodInstance')->willReturn($methodInterface);
        $methodInterface->expects($this->once())->method('getTitle')->willReturn($paymentMethodTitle);
        $this->objectCopyMock->expects($this->once())->method('getDataFromFieldset')->with(
            'quote_convert_payment',
            'to_order_payment',
            $this->paymentMock
        )->willReturn($paymentData);

        $this->paymentMock->expects($this->once())
            ->method('getAdditionalInformation')
            ->willReturn($additionalInfo);
        $ccNumber = 123456798;
        $ccCid = 1234;
        $this->paymentMock->expects($this->once())
            ->method('getCcNumber')
            ->willReturn($ccNumber);
        $this->paymentMock->expects($this->once())
            ->method('getCcCid')
            ->willReturn($ccCid);

        $orderPayment = $this->getMockForAbstractClass(
            \Magento\Sales\Api\Data\OrderPaymentInterface::class,
            [],
            '',
            false,
            true,
            true,
            ['setCcNumber', 'setCcCid', 'setAdditionalInformation']
        );
        $orderPayment->expects($this->once())
            ->method('setAdditionalInformation')
            ->with(array_merge($additionalInfo, [Substitution::INFO_KEY_TITLE => $paymentMethodTitle]))
            ->willReturnSelf();
        $orderPayment->expects($this->once())
            ->method('setCcNumber')
            ->willReturnSelf();
        $orderPayment->expects($this->once())
            ->method('setCcCid')
            ->willReturnSelf();

        $this->orderPaymentRepositoryMock->expects($this->once())->method('create')->willReturn($orderPayment);
        $this->dataObjectHelper->expects($this->once())
            ->method('populateWithArray')
            ->with(
                $orderPayment,
                array_merge($paymentData, $data),
                \Magento\Sales\Api\Data\OrderPaymentInterface::class
            )
            ->willReturnSelf();

        $this->assertSame($orderPayment, $this->converter->convert($this->paymentMock, $data));
    }
}
