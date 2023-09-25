<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Sales\Test\Unit\Controller\Adminhtml\Creditmemo\AbstractCreditmemo;

use Magento\Framework\App\Action\Context;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;
use Magento\Sales\Controller\Adminhtml\Creditmemo\AbstractCreditmemo\Email;

/**
 * Class EmailTest
 *
 * @package Magento\Sales\Controller\Adminhtml\Creditmemo\AbstractCreditmemo
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class EmailTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var Email
     */
    protected $creditmemoEmail;

    /**
     * @var Context|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $context;

    /**
     * @var \Magento\Framework\App\Request\Http|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $request;

    /**
     * @var \Magento\Framework\App\ResponseInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $response;

    /**
     * @var \Magento\Framework\Message\Manager|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $messageManager;

    /**
     * @var \Magento\Framework\ObjectManager\ObjectManager|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $objectManager;

    /**
     * @var \Magento\Backend\Model\Session|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $session;

    /**
     * @var \Magento\Framework\App\ActionFlag|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $actionFlag;

    /**
     * @var \Magento\Backend\Helper\Data|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $helper;

    /**
     * @var \Magento\Backend\Model\View\Result\RedirectFactory|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultRedirectFactoryMock;

    /**
     * @var \Magento\Backend\Model\View\Result\Redirect|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultRedirectMock;

    /**
     * Test setup
     */
    protected function setUp(): void
    {
        $objectManagerHelper = new ObjectManagerHelper($this);
        $this->context = $this->createPartialMock(\Magento\Backend\App\Action\Context::class, [
                'getRequest',
                'getResponse',
                'getMessageManager',
                'getRedirect',
                'getObjectManager',
                'getSession',
                'getActionFlag',
                'getHelper',
                'getResultRedirectFactory'
            ]);
        $this->response = $this->createPartialMock(
            \Magento\Framework\App\ResponseInterface::class,
            ['setRedirect', 'sendResponse']
        );

        $this->request = $this->getMockBuilder(\Magento\Framework\App\Request\Http::class)
            ->disableOriginalConstructor()->getMock();
        $this->objectManager = $this->createPartialMock(
            \Magento\Framework\ObjectManager\ObjectManager::class,
            ['create']
        );
        $this->messageManager = $this->createPartialMock(
            \Magento\Framework\Message\Manager::class,
            ['addSuccessMessage']
        );
        $this->session = $this->createPartialMock(\Magento\Backend\Model\Session::class, ['setIsUrlNotice']);
        $this->actionFlag = $this->createPartialMock(\Magento\Framework\App\ActionFlag::class, ['get']);
        $this->helper = $this->createPartialMock(\Magento\Backend\Helper\Data::class, ['getUrl']);
        $this->resultRedirectFactoryMock = $this->getMockBuilder(
            \Magento\Backend\Model\View\Result\RedirectFactory::class
        )->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->resultRedirectMock = $this->getMockBuilder(\Magento\Backend\Model\View\Result\Redirect::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->context->expects($this->once())->method('getMessageManager')->willReturn($this->messageManager);
        $this->context->expects($this->once())->method('getRequest')->willReturn($this->request);
        $this->context->expects($this->once())->method('getResponse')->willReturn($this->response);
        $this->context->expects($this->once())->method('getObjectManager')->willReturn($this->objectManager);
        $this->context->expects($this->once())->method('getSession')->willReturn($this->session);
        $this->context->expects($this->once())->method('getActionFlag')->willReturn($this->actionFlag);
        $this->context->expects($this->once())->method('getHelper')->willReturn($this->helper);
        $this->context->expects($this->once())
            ->method('getResultRedirectFactory')
            ->willReturn($this->resultRedirectFactoryMock);
        $this->creditmemoEmail = $objectManagerHelper->getObject(
            \Magento\Sales\Controller\Adminhtml\Creditmemo\AbstractCreditmemo\Email::class,
            [
                'context' => $this->context
            ]
        );
    }

    /**
     * testEmail
     */
    public function testEmail()
    {
        $cmId = 10000031;
        $cmManagement = \Magento\Sales\Api\CreditmemoManagementInterface::class;
        $cmManagementMock = $this->createMock($cmManagement);
        $this->prepareRedirect($cmId);

        $this->request->expects($this->once())
            ->method('getParam')
            ->with('creditmemo_id')
            ->willReturn($cmId);
        $this->objectManager->expects($this->once())
            ->method('create')
            ->with($cmManagement)
            ->willReturn($cmManagementMock);
        $cmManagementMock->expects($this->once())
            ->method('notify')
            ->willReturn(true);
        $this->messageManager->expects($this->once())
            ->method('addSuccessMessage')
            ->with('You sent the message.');

        $this->assertInstanceOf(
            \Magento\Backend\Model\View\Result\Redirect::class,
            $this->creditmemoEmail->execute()
        );
        $this->assertEquals($this->response, $this->creditmemoEmail->getResponse());
    }

    /**
     * testEmailNoCreditmemoId
     */
    public function testEmailNoCreditmemoId()
    {
        $this->request->expects($this->once())
            ->method('getParam')
            ->with('creditmemo_id')
            ->willReturn(null);

        $this->assertNull($this->creditmemoEmail->execute());
    }

    /**
     * @param int $cmId
     */
    protected function prepareRedirect($cmId)
    {
        $this->resultRedirectFactoryMock->expects($this->once())
            ->method('create')
            ->willReturn($this->resultRedirectMock);
        $this->resultRedirectMock->expects($this->once())
            ->method('setPath')
            ->with('sales/order_creditmemo/view', ['creditmemo_id' => $cmId])
            ->willReturnSelf();
    }
}
