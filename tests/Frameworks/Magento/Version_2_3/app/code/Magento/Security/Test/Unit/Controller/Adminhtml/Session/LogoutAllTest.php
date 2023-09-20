<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Security\Test\Unit\Controller\Adminhtml\Session;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * Test class for \Magento\Security\Test\Unit\Controller\Adminhtml\Session\LogoutAll testing
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class LogoutAllTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var  \Magento\Security\Controller\Adminhtml\Session\LogoutAll
     */
    protected $controller;

    /**
     * @var \Magento\Backend\App\Action\Context
     */
    protected $contextMock;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @var \Magento\Backend\Model\Session
     */
    protected $session;

    /**
     * @var \Magento\Security\Model\AdminSessionsManager
     */
    protected $sessionsManager;

    /**
     * @var \Magento\Framework\App\ActionFlag
     */
    protected $actionFlagMock;

    /**
     * @var \Magento\Framework\App\ResponseInterface
     */
    protected $responseMock;

    /**
     * @var \Magento\Backend\Helper\Data
     */
    protected $backendHelperMock;

    /**
     * @var  \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $objectManager;

    /**
     * Init mocks for tests
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->contextMock = $this->getMockBuilder(\Magento\Backend\App\Action\Context::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->messageManager = $this->getMockBuilder(\Magento\Framework\Message\ManagerInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['addSuccessMessage', 'addErrorMessage', 'addExceptionMessage'])
            ->getMockForAbstractClass();
        $this->contextMock->expects($this->any())
            ->method('getMessageManager')
            ->willReturn($this->messageManager);

        $this->session = $this->getMockBuilder(\Magento\Backend\Model\Session::class)
            ->disableOriginalConstructor()
            ->setMethods(['setIsUrlNotice'])
            ->getMock();
        $this->contextMock->expects($this->any())
            ->method('getSession')
            ->willReturn($this->session);

        $this->sessionsManager = $this->createPartialMock(
            \Magento\Security\Model\AdminSessionsManager::class,
            ['logoutOtherUserSessions']
        );

        $this->actionFlagMock = $this->getMockBuilder(\Magento\Framework\App\ActionFlag::class)
            ->disableOriginalConstructor()
            ->setMethods(['get'])
            ->getMock();
        $this->contextMock->expects($this->any())
            ->method('getActionFlag')
            ->willReturn($this->actionFlagMock);

        $this->responseMock = $this->getMockBuilder(\Magento\Framework\App\ResponseInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['setRedirect'])
            ->getMockForAbstractClass();
        $this->contextMock->expects($this->any())
            ->method('getResponse')
            ->willReturn($this->responseMock);

        $this->backendHelperMock = $this->createPartialMock(\Magento\Backend\Helper\Data::class, ['getUrl']);
        $this->contextMock->expects($this->any())
            ->method('getHelper')
            ->willReturn($this->backendHelperMock);

        $this->controller = $this->objectManager->getObject(
            \Magento\Security\Controller\Adminhtml\Session\LogoutAll::class,
            [
                'context' => $this->contextMock,
                'sessionsManager' => $this->sessionsManager
            ]
        );
    }

    /**
     * @return void
     */
    public function testExecute()
    {
        $successMessage = 'All other open sessions for this account were terminated.';
        $this->sessionsManager->expects($this->once())
            ->method('logoutOtherUserSessions');
        $this->messageManager->expects($this->once())
            ->method('addSuccessMessage')
            ->with($successMessage);
        $this->messageManager->expects($this->never())
            ->method('addErrorMessage');
        $this->messageManager->expects($this->never())
            ->method('addExceptionMessage');
        $this->responseMock->expects($this->once())
            ->method('setRedirect');
        $this->actionFlagMock->expects($this->once())
            ->method('get')
            ->with('', \Magento\Backend\App\AbstractAction::FLAG_IS_URLS_CHECKED);
        $this->backendHelperMock->expects($this->once())
            ->method('getUrl');
        $this->controller->execute();
    }

    /**
     * @return void
     */
    public function testExecuteLocalizedException()
    {
        $phrase = new \Magento\Framework\Phrase('some error');
        $this->sessionsManager->expects($this->once())
            ->method('logoutOtherUserSessions')
            ->willThrowException(new LocalizedException($phrase));
        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with($phrase);
        $this->controller->execute();
    }

    /**
     * @return void
     */
    public function testExecuteException()
    {
        $phrase = new \Magento\Framework\Phrase('We couldn\'t logout because of an error.');
        $this->sessionsManager->expects($this->once())
            ->method('logoutOtherUserSessions')
            ->willThrowException(new \Exception());
        $this->messageManager->expects($this->once())
            ->method('addExceptionMessage')
            ->with(new \Exception(), $phrase);
        $this->controller->execute();
    }
}
