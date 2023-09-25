<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Customer\Test\Unit\Controller\Account;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Controller\Account\ForgotPasswordPost;
use Magento\Customer\Model\AccountManagement;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\Http as Request;
use Magento\Framework\Controller\Result\Redirect as ResultRedirect;
use Magento\Framework\Controller\Result\RedirectFactory as ResultRedirectFactory;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ForgotPasswordPostTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var ForgotPasswordPost
     */
    protected $controller;

    /**
     * @var Context | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $context;

    /**
     * @var Session | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $session;

    /**
     * @var AccountManagementInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $accountManagement;

    /**
     * @var Escaper | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $escaper;

    /**
     * @var ResultRedirect | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultRedirect;

    /**
     * @var ResultRedirectFactory | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $resultRedirectFactory;

    /**
     * @var Request | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $request;

    /**
     * @var ManagerInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $messageManager;

    protected function setUp(): void
    {
        $this->prepareContext();

        $this->session = $this->getMockBuilder(\Magento\Customer\Model\Session::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->accountManagement = $this->getMockBuilder(\Magento\Customer\Api\AccountManagementInterface::class)
            ->getMockForAbstractClass();

        $this->escaper = $this->getMockBuilder(\Magento\Framework\Escaper::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->controller = new ForgotPasswordPost(
            $this->context,
            $this->session,
            $this->accountManagement,
            $this->escaper
        );
    }

    public function testExecuteEmptyEmail()
    {
        $this->request->expects($this->once())
            ->method('getPost')
            ->with('email')
            ->willReturn(null);

        $this->messageManager->expects($this->once())
            ->method('addErrorMessage')
            ->with(__('Please enter your email.'))
            ->willReturnSelf();

        $this->resultRedirect->expects($this->once())
            ->method('setPath')
            ->with('*/*/forgotpassword')
            ->willReturnSelf();

        $this->assertSame($this->resultRedirect, $this->controller->execute());
    }

    public function testExecute()
    {
        $email = 'user1@example.com';

        $this->request->expects($this->once())
            ->method('getPost')
            ->with('email')
            ->willReturn($email);

        $this->accountManagement->expects($this->once())
            ->method('initiatePasswordReset')
            ->with($email, AccountManagement::EMAIL_RESET)
            ->willReturnSelf();

        $this->escaper->expects($this->once())
            ->method('escapeHtml')
            ->with($email)
            ->willReturn($email);

        $message = __(
            'If there is an account associated with %1 you will receive an email with a link to reset your password.',
            $email
        );
        $this->messageManager->expects($this->once())
            ->method('addSuccessMessage')
            ->with($message)
            ->willReturnSelf();

        $this->resultRedirect->expects($this->once())
            ->method('setPath')
            ->with('*/*/')
            ->willReturnSelf();

        $this->controller->execute();
    }

    public function testExecuteNoSuchEntityException()
    {
        $email = 'user1@example.com';

        $this->request->expects($this->once())
            ->method('getPost')
            ->with('email')
            ->willReturn($email);

        $this->accountManagement->expects($this->once())
            ->method('initiatePasswordReset')
            ->with($email, AccountManagement::EMAIL_RESET)
            ->willThrowException(new NoSuchEntityException(__('NoSuchEntityException')));

        $this->escaper->expects($this->once())
            ->method('escapeHtml')
            ->with($email)
            ->willReturn($email);

        $message = __(
            'If there is an account associated with %1 you will receive an email with a link to reset your password.',
            $email
        );
        $this->messageManager->expects($this->once())
            ->method('addSuccessMessage')
            ->with($message)
            ->willReturnSelf();

        $this->resultRedirect->expects($this->once())
            ->method('setPath')
            ->with('*/*/')
            ->willReturnSelf();

        $this->controller->execute();
    }

    public function testExecuteException()
    {
        $email = 'user1@example.com';
        $exception = new \Exception(__('Exception'));

        $this->request->expects($this->once())
            ->method('getPost')
            ->with('email')
            ->willReturn($email);

        $this->accountManagement->expects($this->once())
            ->method('initiatePasswordReset')
            ->with($email, AccountManagement::EMAIL_RESET)
            ->willThrowException($exception);

        $this->messageManager->expects($this->once())
            ->method('addExceptionMessage')
            ->with($exception, __('We\'re unable to send the password reset email.'))
            ->willReturnSelf();

        $this->resultRedirect->expects($this->once())
            ->method('setPath')
            ->with('*/*/forgotpassword')
            ->willReturnSelf();

        $this->controller->execute();
    }

    protected function prepareContext()
    {
        $this->resultRedirect = $this->getMockBuilder(\Magento\Framework\Controller\Result\Redirect::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->resultRedirectFactory = $this->getMockBuilder(
            \Magento\Framework\Controller\Result\RedirectFactory::class
        )
            ->disableOriginalConstructor()
            ->getMock();

        $this->context = $this->getMockBuilder(\Magento\Framework\App\Action\Context::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->request = $this->getMockBuilder(\Magento\Framework\App\Request\Http::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getPost',
            ])
            ->getMock();

        $this->messageManager = $this->getMockBuilder(\Magento\Framework\Message\ManagerInterface::class)
            ->getMockForAbstractClass();

        $this->resultRedirectFactory->expects($this->any())
            ->method('create')
            ->willReturn($this->resultRedirect);

        $this->context->expects($this->any())
            ->method('getResultRedirectFactory')
            ->willReturn($this->resultRedirectFactory);

        $this->context->expects($this->any())
            ->method('getRequest')
            ->willReturn($this->request);

        $this->context->expects($this->any())
            ->method('getMessageManager')
            ->willReturn($this->messageManager);
    }
}
