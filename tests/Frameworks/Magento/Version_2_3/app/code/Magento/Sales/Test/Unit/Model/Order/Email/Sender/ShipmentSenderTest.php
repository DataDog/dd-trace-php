<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Test\Unit\Model\Order\Email\Sender;

use Magento\Sales\Model\Order\Email\Sender\ShipmentSender;

/**
 * Test for Magento\Sales\Model\Order\Email\Sender\ShipmentSender class.
 */
class ShipmentSenderTest extends AbstractSenderTest
{
    private const SHIPMENT_ID = 1;

    private const ORDER_ID = 1;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\ShipmentSender
     */
    protected $sender;

    /**
     * @var \Magento\Sales\Model\Order\Shipment|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $shipmentMock;

    /**
     * @var \Magento\Sales\Model\ResourceModel\EntityAbstract|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $shipmentResourceMock;

    protected function setUp(): void
    {
        $this->stepMockSetup();

        $this->shipmentResourceMock = $this->createPartialMock(
            \Magento\Sales\Model\ResourceModel\Order\Shipment::class,
            ['saveAttribute']
        );

        $this->shipmentMock = $this->createPartialMock(
            \Magento\Sales\Model\Order\Shipment::class,
            [
                'getStore',
                'getId',
                '__wakeup',
                'getOrder',
                'setSendEmail',
                'setEmailSent',
                'getCustomerNoteNotify',
                'getCustomerNote'
            ]
        );
        $this->shipmentMock->expects($this->any())
            ->method('getStore')
            ->willReturn($this->storeMock);
        $this->shipmentMock->expects($this->any())
            ->method('getOrder')
            ->willReturn($this->orderMock);

        $this->shipmentMock->method('getId')
            ->willReturn(self::SHIPMENT_ID);
        $this->orderMock->method('getId')
            ->willReturn(self::ORDER_ID);

        $this->identityContainerMock = $this->createPartialMock(
            \Magento\Sales\Model\Order\Email\Container\ShipmentIdentity::class,
            ['getStore', 'isEnabled', 'getConfigValue', 'getTemplateId', 'getGuestTemplateId', 'getCopyMethod']
        );
        $this->identityContainerMock->expects($this->any())
            ->method('getStore')
            ->willReturn($this->storeMock);

        $this->sender = new ShipmentSender(
            $this->templateContainerMock,
            $this->identityContainerMock,
            $this->senderBuilderFactoryMock,
            $this->loggerMock,
            $this->addressRenderer,
            $this->paymentHelper,
            $this->shipmentResourceMock,
            $this->globalConfig,
            $this->eventManagerMock
        );
    }

    /**
     * @param int $configValue
     * @param bool|null $forceSyncMode
     * @param bool|null $customerNoteNotify
     * @param bool|null $emailSendingResult
     * @dataProvider sendDataProvider
     * @return void
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testSend($configValue, $forceSyncMode, $customerNoteNotify, $emailSendingResult)
    {
        $comment = 'comment_test';
        $address = 'address_test';
        $configPath = 'sales_email/general/async_sending';
        $customerName = 'Test Customer';
        $isNotVirtual = true;
        $frontendStatusLabel = 'Processing';

        $this->shipmentMock->expects($this->once())
            ->method('setSendEmail')
            ->with($emailSendingResult);

        $this->globalConfig->expects($this->once())
            ->method('getValue')
            ->with($configPath)
            ->willReturn($configValue);

        if (!$configValue || $forceSyncMode) {
            $addressMock = $this->createMock(\Magento\Sales\Model\Order\Address::class);

            $this->addressRenderer->expects($this->any())
                ->method('format')
                ->with($addressMock, 'html')
                ->willReturn($address);

            $this->orderMock->expects($this->any())
                ->method('getBillingAddress')
                ->willReturn($addressMock);

            $this->orderMock->expects($this->any())
                ->method('getShippingAddress')
                ->willReturn($addressMock);

            $this->shipmentMock->expects($this->once())
                ->method('getCustomerNoteNotify')
                ->willReturn($customerNoteNotify);

            $this->shipmentMock->expects($this->any())
                ->method('getCustomerNote')
                ->willReturn($comment);

            $this->orderMock->expects($this->any())
                ->method('getCustomerName')
                ->willReturn($customerName);

            $this->orderMock->expects($this->once())
                ->method('getIsNotVirtual')
                ->willReturn($isNotVirtual);

            $this->orderMock->expects($this->once())
                ->method('getEmailCustomerNote')
                ->willReturn('');

            $this->orderMock->expects($this->once())
                ->method('getFrontendStatusLabel')
                ->willReturn($frontendStatusLabel);

            $this->templateContainerMock->expects($this->once())
                ->method('setTemplateVars')
                ->with(
                    [
                        'order' => $this->orderMock,
                        'order_id' => self::ORDER_ID,
                        'shipment' => $this->shipmentMock,
                        'shipment_id' => self::SHIPMENT_ID,
                        'comment' => $customerNoteNotify ? $comment : '',
                        'billing' => $addressMock,
                        'payment_html' => 'payment',
                        'store' => $this->storeMock,
                        'formattedShippingAddress' => $address,
                        'formattedBillingAddress' => $address,
                        'order_data' => [
                            'customer_name' => $customerName,
                            'is_not_virtual' => $isNotVirtual,
                            'email_customer_note' => '',
                            'frontend_status_label' => $frontendStatusLabel
                        ]
                    ]
                );

            $this->identityContainerMock->expects($this->exactly(2))
                ->method('isEnabled')
                ->willReturn($emailSendingResult);

            if ($emailSendingResult) {
                $this->identityContainerMock->expects($this->once())
                    ->method('getCopyMethod')
                    ->willReturn('copy');

                $this->senderBuilderFactoryMock->expects($this->once())
                    ->method('create')
                    ->willReturn($this->senderMock);

                $this->senderMock->expects($this->once())->method('send');

                $this->senderMock->expects($this->once())->method('sendCopyTo');

                $this->shipmentMock->expects($this->once())
                    ->method('setEmailSent')
                    ->with(true);

                $this->shipmentResourceMock->expects($this->once())
                    ->method('saveAttribute')
                    ->with($this->shipmentMock, ['send_email', 'email_sent']);

                $this->assertTrue(
                    $this->sender->send($this->shipmentMock)
                );
            } else {
                $this->shipmentResourceMock->expects($this->once())
                    ->method('saveAttribute')
                    ->with($this->shipmentMock, 'send_email');

                $this->assertFalse(
                    $this->sender->send($this->shipmentMock)
                );
            }
        } else {
            $this->shipmentResourceMock->expects($this->at(0))
                ->method('saveAttribute')
                ->with($this->shipmentMock, 'email_sent');
            $this->shipmentResourceMock->expects($this->at(1))
                ->method('saveAttribute')
                ->with($this->shipmentMock, 'send_email');

            $this->assertFalse(
                $this->sender->send($this->shipmentMock)
            );
        }
    }

    /**
     * @return array
     */
    public function sendDataProvider()
    {
        return [
            [0, 0, 1, true],
            [0, 0, 0, true],
            [0, 0, 1, false],
            [0, 0, 0, false],
            [0, 1, 1, true],
            [0, 1, 0, true],
            [1, null, null, null]
        ];
    }

    /**
     * @param bool $isVirtualOrder
     * @param int $formatCallCount
     * @param string|null $expectedShippingAddress
     * @dataProvider sendVirtualOrderDataProvider
     */
    public function testSendVirtualOrder($isVirtualOrder, $formatCallCount, $expectedShippingAddress)
    {
        $address = 'address_test';
        $this->orderMock->setData(\Magento\Sales\Api\Data\OrderInterface::IS_VIRTUAL, $isVirtualOrder);
        $customerName = 'Test Customer';
        $frontendStatusLabel = 'Complete';
        $isNotVirtual = false;

        $this->shipmentMock->expects($this->once())
            ->method('setSendEmail')
            ->with(false);

        $this->globalConfig->expects($this->once())
            ->method('getValue')
            ->with('sales_email/general/async_sending')
            ->willReturn(false);

        $addressMock = $this->createMock(\Magento\Sales\Model\Order\Address::class);

        $this->addressRenderer->expects($this->exactly($formatCallCount))
            ->method('format')
            ->with($addressMock, 'html')
            ->willReturn($address);

        $this->stepAddressFormat($addressMock, $isVirtualOrder);

        $this->shipmentMock->expects($this->once())
            ->method('getCustomerNoteNotify')
            ->willReturn(false);

        $this->orderMock->expects($this->any())
            ->method('getCustomerName')
            ->willReturn($customerName);

        $this->orderMock->expects($this->once())
            ->method('getIsNotVirtual')
            ->willReturn($isNotVirtual);

        $this->orderMock->expects($this->once())
            ->method('getEmailCustomerNote')
            ->willReturn('');

        $this->orderMock->expects($this->once())
            ->method('getFrontendStatusLabel')
            ->willReturn($frontendStatusLabel);

        $this->templateContainerMock->expects($this->once())
            ->method('setTemplateVars')
            ->with(
                [
                    'order' => $this->orderMock,
                    'order_id' => self::ORDER_ID,
                    'shipment' => $this->shipmentMock,
                    'shipment_id' => self::SHIPMENT_ID,
                    'comment' => '',
                    'billing' => $addressMock,
                    'payment_html' => 'payment',
                    'store' => $this->storeMock,
                    'formattedShippingAddress' => $expectedShippingAddress,
                    'formattedBillingAddress' => $address,
                    'order_data' => [
                        'customer_name' => $customerName,
                        'is_not_virtual' => false,
                        'email_customer_note' => '',
                        'frontend_status_label' => $frontendStatusLabel
                    ]
                ]
            );

        $this->identityContainerMock->expects($this->exactly(2))
            ->method('isEnabled')
            ->willReturn(false);
        $this->shipmentResourceMock->expects($this->once())
            ->method('saveAttribute')
            ->with($this->shipmentMock, 'send_email');

        $this->assertFalse($this->sender->send($this->shipmentMock));
    }

    /**
     * @return array
     */
    public function sendVirtualOrderDataProvider()
    {
        return [
            [true, 1, null],
            [false, 2, 'address_test']
        ];
    }
}
