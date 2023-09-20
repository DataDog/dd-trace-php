<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Test\Unit\Model\Order\Email\Sender;

use Magento\Sales\Model\Order\Email\Sender\ShipmentCommentSender;

class ShipmentCommentSenderTest extends AbstractSenderTest
{
    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\ShipmentCommentSender
     */
    protected $sender;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $shipmentMock;

    protected function setUp(): void
    {
        $this->stepMockSetup();
        $this->stepIdentityContainerInit(\Magento\Sales\Model\Order\Email\Container\ShipmentCommentIdentity::class);
        $this->addressRenderer->expects($this->any())->method('format')->willReturn(1);
        $this->shipmentMock = $this->createPartialMock(
            \Magento\Sales\Model\Order\Shipment::class,
            ['getStore', '__wakeup', 'getOrder']
        );
        $this->shipmentMock->expects($this->any())
            ->method('getStore')
            ->willReturn($this->storeMock);
        $this->shipmentMock->expects($this->any())
            ->method('getOrder')
            ->willReturn($this->orderMock);

        $this->sender = new ShipmentCommentSender(
            $this->templateContainerMock,
            $this->identityContainerMock,
            $this->senderBuilderFactoryMock,
            $this->loggerMock,
            $this->addressRenderer,
            $this->eventManagerMock
        );
    }

    public function testSendFalse()
    {
        $this->stepAddressFormat($this->addressMock);
        $result = $this->sender->send($this->shipmentMock);
        $this->assertFalse($result);
    }

    public function testSendTrueWithCustomerCopy()
    {
        $billingAddress = $this->addressMock;
        $comment = 'comment_test';
        $customerName='Test Customer';
        $frontendStatusLabel='Processing';

        $this->orderMock->expects($this->once())
            ->method('getCustomerIsGuest')
            ->willReturn(false);
        $this->stepAddressFormat($billingAddress);

        $this->identityContainerMock->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);
        $this->orderMock->expects($this->any())
            ->method('getCustomerName')
            ->willReturn($customerName);
        $this->orderMock->expects($this->once())
            ->method('getFrontendStatusLabel')
            ->willReturn($frontendStatusLabel);
        $this->templateContainerMock->expects($this->once())
            ->method('setTemplateVars')
            ->with(
                $this->equalTo(
                    [
                        'order' => $this->orderMock,
                        'shipment' => $this->shipmentMock,
                        'billing' => $billingAddress,
                        'comment' => $comment,
                        'store' => $this->storeMock,
                        'formattedShippingAddress' => 1,
                        'formattedBillingAddress' => 1,
                        'order_data' => [
                            'customer_name' => $customerName,
                            'frontend_status_label' => $frontendStatusLabel
                        ]
                    ]
                )
            );
        $this->stepSendWithoutSendCopy();
        $result = $this->sender->send($this->shipmentMock, true, $comment);
        $this->assertTrue($result);
    }

    public function testSendTrueWithoutCustomerCopy()
    {
        $billingAddress = $this->addressMock;
        $comment = 'comment_test';
        $customerName='Test Customer';
        $frontendStatusLabel='Processing';

        $this->orderMock->expects($this->once())
            ->method('getCustomerIsGuest')
            ->willReturn(false);
        $this->stepAddressFormat($billingAddress);

        $this->identityContainerMock->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);
        $this->orderMock->expects($this->any())
            ->method('getCustomerName')
            ->willReturn($customerName);
        $this->orderMock->expects($this->once())
            ->method('getFrontendStatusLabel')
            ->willReturn($frontendStatusLabel);
        $this->templateContainerMock->expects($this->once())
            ->method('setTemplateVars')
            ->with(
                $this->equalTo(
                    [
                        'order' => $this->orderMock,
                        'shipment' => $this->shipmentMock,
                        'billing' => $billingAddress,
                        'comment' => $comment,
                        'store' => $this->storeMock,
                        'formattedShippingAddress' => 1,
                        'formattedBillingAddress' => 1,
                        'order_data' => [
                            'customer_name' => $customerName,
                            'frontend_status_label' => $frontendStatusLabel
                        ]
                    ]
                )
            );
        $this->stepSendWithCallSendCopyTo();
        $result = $this->sender->send($this->shipmentMock, false, $comment);
        $this->assertTrue($result);
    }

    public function testSendVirtualOrder()
    {
        $isVirtualOrder = true;
        $this->orderMock->setData(\Magento\Sales\Api\Data\OrderInterface::IS_VIRTUAL, $isVirtualOrder);
        $this->stepAddressFormat($this->addressMock, $isVirtualOrder);
        $customerName='Test Customer';
        $frontendStatusLabel='Complete';

        $this->identityContainerMock->expects($this->once())
            ->method('isEnabled')
            ->willReturn(false);
        $this->orderMock->expects($this->any())
            ->method('getCustomerName')
            ->willReturn($customerName);
        $this->orderMock->expects($this->once())
            ->method('getFrontendStatusLabel')
            ->willReturn($frontendStatusLabel);
        $this->templateContainerMock->expects($this->once())
            ->method('setTemplateVars')
            ->with(
                $this->equalTo(
                    [
                        'order' => $this->orderMock,
                        'shipment' => $this->shipmentMock,
                        'billing' => $this->addressMock,
                        'comment' => '',
                        'store' => $this->storeMock,
                        'formattedShippingAddress' => null,
                        'formattedBillingAddress' => 1,
                        'order_data' => [
                            'customer_name' => $customerName,
                            'frontend_status_label' => $frontendStatusLabel
                        ]

                    ]
                )
            );
        $this->assertFalse($this->sender->send($this->shipmentMock));
    }
}
