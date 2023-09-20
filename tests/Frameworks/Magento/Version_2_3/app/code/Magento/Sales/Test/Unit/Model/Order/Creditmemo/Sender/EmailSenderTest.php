<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Test\Unit\Model\Order\Creditmemo\Sender;

/**
 * Unit test for email notification sender for Creditmemo.
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class EmailSenderTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Sales\Model\Order\Creditmemo\Sender\EmailSender
     */
    private $subject;

    /**
     * @var \Magento\Sales\Model\Order|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orderMock;

    /**
     * @var \Magento\Store\Model\Store|\PHPUnit\Framework\MockObject\MockObject
     */
    private $storeMock;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender|\PHPUnit\Framework\MockObject\MockObject
     */
    private $senderMock;

    /**
     * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $loggerMock;

    /**
     * @var \Magento\Sales\Api\Data\CreditmemoInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $creditmemoMock;

    /**
     * @var \Magento\Sales\Api\Data\CreditmemoCommentCreationInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $commentMock;

    /**
     * @var \Magento\Sales\Model\Order\Address|\PHPUnit\Framework\MockObject\MockObject
     */
    private $addressMock;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $globalConfigMock;

    /**
     * @var \Magento\Framework\Event\ManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $eventManagerMock;

    /**
     * @var \Magento\Payment\Model\Info|\PHPUnit\Framework\MockObject\MockObject
     */
    private $paymentInfoMock;

    /**
     * @var \Magento\Payment\Helper\Data|\PHPUnit\Framework\MockObject\MockObject
     */
    private $paymentHelperMock;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\Creditmemo|\PHPUnit\Framework\MockObject\MockObject
     */
    private $creditmemoResourceMock;

    /**
     * @var \Magento\Sales\Model\Order\Address\Renderer|\PHPUnit\Framework\MockObject\MockObject
     */
    private $addressRendererMock;

    /**
     * @var \Magento\Sales\Model\Order\Email\Container\Template|\PHPUnit\Framework\MockObject\MockObject
     */
    private $templateContainerMock;

    /**
     * @var \Magento\Sales\Model\Order\Email\Container\CreditmemoIdentity|\PHPUnit\Framework\MockObject\MockObject
     */
    private $identityContainerMock;

    /**
     * @var \Magento\Sales\Model\Order\Email\SenderBuilderFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    private $senderBuilderFactoryMock;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function setUp(): void
    {
        $this->orderMock = $this->getMockBuilder(\Magento\Sales\Model\Order::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->storeMock = $this->getMockBuilder(\Magento\Store\Model\Store::class)
            ->setMethods(['getStoreId'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->storeMock->expects($this->any())
            ->method('getStoreId')
            ->willReturn(1);
        $this->orderMock->expects($this->any())
            ->method('getStore')
            ->willReturn($this->storeMock);

        $this->senderMock = $this->getMockBuilder(\Magento\Sales\Model\Order\Email\Sender::class)
            ->disableOriginalConstructor()
            ->setMethods(['send', 'sendCopyTo'])
            ->getMock();

        $this->loggerMock = $this->getMockBuilder(\Psr\Log\LoggerInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->creditmemoMock = $this->getMockBuilder(\Magento\Sales\Model\Order\Creditmemo::class)
            ->disableOriginalConstructor()
            ->setMethods(['setSendEmail', 'setEmailSent'])
            ->getMock();

        $this->commentMock = $this->getMockBuilder(\Magento\Sales\Api\Data\CreditmemoCommentCreationInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->commentMock->expects($this->any())
            ->method('getComment')
            ->willReturn('Comment text');

        $this->addressMock = $this->getMockBuilder(\Magento\Sales\Model\Order\Address::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->orderMock->expects($this->any())
            ->method('getBillingAddress')
            ->willReturn($this->addressMock);
        $this->orderMock->expects($this->any())
            ->method('getShippingAddress')
            ->willReturn($this->addressMock);

        $this->globalConfigMock = $this->getMockBuilder(\Magento\Framework\App\Config\ScopeConfigInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->eventManagerMock = $this->getMockBuilder(\Magento\Framework\Event\ManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->paymentInfoMock = $this->getMockBuilder(\Magento\Payment\Model\Info::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->orderMock->expects($this->any())
            ->method('getPayment')
            ->willReturn($this->paymentInfoMock);

        $this->paymentHelperMock = $this->getMockBuilder(\Magento\Payment\Helper\Data::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->paymentHelperMock->expects($this->any())
            ->method('getInfoBlockHtml')
            ->with($this->paymentInfoMock, 1)
            ->willReturn('Payment Info Block');

        $this->creditmemoResourceMock = $this->getMockBuilder(
            \Magento\Sales\Model\ResourceModel\Order\Creditmemo::class
        )->disableOriginalConstructor()
            ->getMock();

        $this->addressRendererMock = $this->getMockBuilder(\Magento\Sales\Model\Order\Address\Renderer::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->addressRendererMock->expects($this->any())
            ->method('format')
            ->with($this->addressMock, 'html')
            ->willReturn('Formatted address');

        $this->templateContainerMock = $this->getMockBuilder(\Magento\Sales\Model\Order\Email\Container\Template::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->identityContainerMock = $this->getMockBuilder(
            \Magento\Sales\Model\Order\Email\Container\CreditmemoIdentity::class
        )
        ->disableOriginalConstructor()
        ->getMock();

        $this->identityContainerMock->expects($this->any())
            ->method('getStore')
            ->willReturn($this->storeMock);

        $this->senderBuilderFactoryMock = $this->getMockBuilder(
            \Magento\Sales\Model\Order\Email\SenderBuilderFactory::class
        )
        ->disableOriginalConstructor()
        ->setMethods(['create'])
        ->getMock();

        $this->subject = new \Magento\Sales\Model\Order\Creditmemo\Sender\EmailSender(
            $this->templateContainerMock,
            $this->identityContainerMock,
            $this->senderBuilderFactoryMock,
            $this->loggerMock,
            $this->addressRendererMock,
            $this->paymentHelperMock,
            $this->creditmemoResourceMock,
            $this->globalConfigMock,
            $this->eventManagerMock
        );
    }

    /**
     * @param int $configValue
     * @param bool $forceSyncMode
     * @param bool $isComment
     * @param bool $emailSendingResult
     *
     * @dataProvider sendDataProvider
     *
     * @return void
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function testSend($configValue, $forceSyncMode, $isComment, $emailSendingResult)
    {
        $this->globalConfigMock->expects($this->once())
            ->method('getValue')
            ->with('sales_email/general/async_sending')
            ->willReturn($configValue);

        if (!$isComment) {
            $this->commentMock = null;
        }

        $this->creditmemoMock->expects($this->once())
            ->method('setSendEmail')
            ->with($emailSendingResult);

        if (!$configValue || $forceSyncMode) {
            $transport = [
                'order' => $this->orderMock,
                'creditmemo' => $this->creditmemoMock,
                'comment' => $isComment ? 'Comment text' : '',
                'billing' => $this->addressMock,
                'payment_html' => 'Payment Info Block',
                'store' => $this->storeMock,
                'formattedShippingAddress' => 'Formatted address',
                'formattedBillingAddress' => 'Formatted address',
            ];
            $transport = new \Magento\Framework\DataObject($transport);

            $this->eventManagerMock->expects($this->once())
                ->method('dispatch')
                ->with(
                    'email_creditmemo_set_template_vars_before',
                    [
                        'sender' => $this->subject,
                        'transport' => $transport->getData(),
                        'transportObject' => $transport
                    ]
                );

            $this->templateContainerMock->expects($this->once())
                ->method('setTemplateVars')
                ->with($transport->getData());

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

                $this->senderMock->expects($this->once())
                    ->method('send');

                $this->senderMock->expects($this->once())
                    ->method('sendCopyTo');

                $this->creditmemoMock->expects($this->once())
                    ->method('setEmailSent')
                    ->with(true);

                $this->creditmemoResourceMock->expects($this->once())
                    ->method('saveAttribute')
                    ->with($this->creditmemoMock, ['send_email', 'email_sent']);

                $this->assertTrue(
                    $this->subject->send(
                        $this->orderMock,
                        $this->creditmemoMock,
                        $this->commentMock,
                        $forceSyncMode
                    )
                );
            } else {
                $this->creditmemoResourceMock->expects($this->once())
                    ->method('saveAttribute')
                    ->with($this->creditmemoMock, 'send_email');

                $this->assertFalse(
                    $this->subject->send(
                        $this->orderMock,
                        $this->creditmemoMock,
                        $this->commentMock,
                        $forceSyncMode
                    )
                );
            }
        } else {
            $this->creditmemoMock->expects($this->once())
                ->method('setEmailSent')
                ->with(null);

            $this->creditmemoResourceMock->expects($this->at(0))
                ->method('saveAttribute')
                ->with($this->creditmemoMock, 'email_sent');
            $this->creditmemoResourceMock->expects($this->at(1))
                ->method('saveAttribute')
                ->with($this->creditmemoMock, 'send_email');

            $this->assertFalse(
                $this->subject->send(
                    $this->orderMock,
                    $this->creditmemoMock,
                    $this->commentMock,
                    $forceSyncMode
                )
            );
        }
    }

    /**
     * @return array
     */
    public function sendDataProvider()
    {
        return [
            'Successful sync sending with comment' => [0, false, true, true],
            'Successful sync sending without comment' => [0, false, false, true],
            'Failed sync sending with comment' => [0, false, true, false],
            'Successful forced sync sending with comment' => [1, true, true, true],
            'Async sending' => [1, false, false, false],
        ];
    }
}
