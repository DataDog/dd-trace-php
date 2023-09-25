<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Sales\Test\Unit\Model;

use Magento\Framework\Exception\MailException;

use Magento\Sales\Model\OrderNotifier;
use Magento\Sales\Model\ResourceModel\Order\Status\History\CollectionFactory;

/**
 * Class OrderNotifierTest
 */
class OrderNotifierTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var CollectionFactory |\PHPUnit\Framework\MockObject\MockObject
     */
    protected $historyCollectionFactory;

    /**
     * @var \Magento\Sales\Model\OrderNotifier
     */
    protected $notifier;

    /**
     * @var \Magento\Sales\Model\Order|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $order;

    /**
     * @var \Magento\Framework\ObjectManagerInterface |\PHPUnit\Framework\MockObject\MockObject
     */
    protected $loggerMock;

    /**
     * @var \Magento\Framework\ObjectManager\ObjectManager |\PHPUnit\Framework\MockObject\MockObject
     */
    protected $orderSenderMock;

    protected function setUp(): void
    {
        $this->historyCollectionFactory = $this->createPartialMock(
            \Magento\Sales\Model\ResourceModel\Order\Status\History\CollectionFactory::class,
            ['create']
        );
        $this->order = $this->createPartialMock(\Magento\Sales\Model\Order::class, ['__wakeUp', 'getEmailSent']);
        $this->orderSenderMock = $this->createPartialMock(
            \Magento\Sales\Model\Order\Email\Sender\OrderSender::class,
            ['send']
        );
        $this->loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->notifier = new OrderNotifier(
            $this->historyCollectionFactory,
            $this->loggerMock,
            $this->orderSenderMock
        );
    }

    /**
     * Test case for successful email sending
     */
    public function testNotifySuccess()
    {
        $historyCollection = $this->createPartialMock(
            \Magento\Sales\Model\ResourceModel\Order\Status\History\Collection::class,
            ['getUnnotifiedForInstance', 'save', 'setIsCustomerNotified']
        );
        $historyItem = $this->createPartialMock(
            \Magento\Sales\Model\Order\Status\History::class,
            ['setIsCustomerNotified', 'save', '__wakeUp']
        );
        $historyItem->expects($this->at(0))
            ->method('setIsCustomerNotified')
            ->with(1);
        $historyItem->expects($this->at(1))
            ->method('save');
        $historyCollection->expects($this->once())
            ->method('getUnnotifiedForInstance')
            ->with($this->order)
            ->willReturn($historyItem);
        $this->order->expects($this->once())
            ->method('getEmailSent')
            ->willReturn(true);
        $this->historyCollectionFactory->expects($this->once())
            ->method('create')
            ->willReturn($historyCollection);

        $this->orderSenderMock->expects($this->once())
            ->method('send')
            ->with($this->equalTo($this->order));

        $this->assertTrue($this->notifier->notify($this->order));
    }

    /**
     * Test case when email has not been sent
     */
    public function testNotifyFail()
    {
        $this->order->expects($this->once())
            ->method('getEmailSent')
            ->willReturn(false);
        $this->assertFalse($this->notifier->notify($this->order));
    }

    /**
     * Test case when Mail Exception has been thrown
     */
    public function testNotifyException()
    {
        $exception = new MailException(__('Email has not been sent'));
        $this->orderSenderMock->expects($this->once())
            ->method('send')
            ->with($this->equalTo($this->order))
            ->will($this->throwException($exception));
        $this->loggerMock->expects($this->once())
            ->method('critical')
            ->with($this->equalTo($exception));
        $this->assertFalse($this->notifier->notify($this->order));
    }
}
