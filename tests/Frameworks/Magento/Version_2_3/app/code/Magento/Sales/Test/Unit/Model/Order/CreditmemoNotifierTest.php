<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Sales\Test\Unit\Model\Order;

use Magento\Framework\Exception\MailException;

use Magento\Sales\Model\Order\CreditmemoNotifier;
use Magento\Sales\Model\ResourceModel\Order\Status\History\CollectionFactory;

/**
 * Class CreditmemoNotifierTest
 */
class CreditmemoNotifierTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var CollectionFactory |\PHPUnit\Framework\MockObject\MockObject
     */
    protected $historyCollectionFactory;

    /**
     * @var CreditmemoNotifier
     */
    protected $notifier;

    /**
     * @var \Magento\Sales\Model\Order\Creditmemo|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $creditmemo;

    /**
     * @var \Magento\Framework\ObjectManagerInterface |\PHPUnit\Framework\MockObject\MockObject
     */
    protected $loggerMock;

    /**
     * @var \Magento\Framework\ObjectManager\ObjectManager |\PHPUnit\Framework\MockObject\MockObject
     */
    protected $creditmemoSenderMock;

    protected function setUp(): void
    {
        $this->historyCollectionFactory = $this->createPartialMock(
            \Magento\Sales\Model\ResourceModel\Order\Status\History\CollectionFactory::class,
            ['create']
        );
        $this->creditmemo = $this->createPartialMock(
            \Magento\Sales\Model\Order\Creditmemo::class,
            ['__wakeUp', 'getEmailSent']
        );
        $this->creditmemoSenderMock = $this->createPartialMock(
            \Magento\Sales\Model\Order\Email\Sender\CreditmemoSender::class,
            ['send']
        );
        $this->loggerMock = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->notifier = new CreditmemoNotifier(
            $this->historyCollectionFactory,
            $this->loggerMock,
            $this->creditmemoSenderMock
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
            ->with($this->creditmemo)
            ->willReturn($historyItem);
        $this->creditmemo->expects($this->once())
            ->method('getEmailSent')
            ->willReturn(true);
        $this->historyCollectionFactory->expects($this->once())
            ->method('create')
            ->willReturn($historyCollection);

        $this->creditmemoSenderMock->expects($this->once())
            ->method('send')
            ->with($this->equalTo($this->creditmemo));

        $this->assertTrue($this->notifier->notify($this->creditmemo));
    }

    /**
     * Test case when email has not been sent
     */
    public function testNotifyFail()
    {
        $this->creditmemo->expects($this->once())
            ->method('getEmailSent')
            ->willReturn(false);
        $this->assertFalse($this->notifier->notify($this->creditmemo));
    }

    /**
     * Test case when Mail Exception has been thrown
     */
    public function testNotifyException()
    {
        $exception = new MailException(__('Email has not been sent'));
        $this->creditmemoSenderMock->expects($this->once())
            ->method('send')
            ->with($this->equalTo($this->creditmemo))
            ->will($this->throwException($exception));
        $this->loggerMock->expects($this->once())
            ->method('critical')
            ->with($this->equalTo($exception));
        $this->assertFalse($this->notifier->notify($this->creditmemo));
    }
}
