<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Wishlist\Test\Unit\Controller\Index;

use Magento\Customer\Model\Data\Customer as CustomerData;
use Magento\Framework\App\Action\Context as ActionContext;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect as ResultRedirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\Layout as ResultLayout;
use Magento\Store\Model\Store;
use Magento\Wishlist\Controller\Index\Send;
use Magento\Wishlist\Controller\WishlistProviderInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Captcha\Helper\Data as CaptchaHelper;
use Magento\Captcha\Model\DefaultModel as CaptchaModel;
use Magento\Customer\Model\Session;

/**
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SendTest extends \PHPUnit\Framework\TestCase
{
    /** @var  Send |\PHPUnit\Framework\MockObject\MockObject */
    protected $model;

    /** @var  ActionContext |\PHPUnit\Framework\MockObject\MockObject */
    protected $context;

    /** @var  FormKeyValidator |\PHPUnit\Framework\MockObject\MockObject */
    protected $formKeyValidator;

    /** @var  WishlistProviderInterface |\PHPUnit\Framework\MockObject\MockObject */
    protected $wishlistProvider;

    /** @var  Store |\PHPUnit\Framework\MockObject\MockObject */
    protected $store;

    /** @var  ResultFactory |\PHPUnit\Framework\MockObject\MockObject */
    protected $resultFactory;

    /** @var  ResultRedirect |\PHPUnit\Framework\MockObject\MockObject */
    protected $resultRedirect;

    /** @var  ResultLayout |\PHPUnit\Framework\MockObject\MockObject */
    protected $resultLayout;

    /** @var  RequestInterface |\PHPUnit\Framework\MockObject\MockObject */
    protected $request;

    /** @var  ManagerInterface |\PHPUnit\Framework\MockObject\MockObject */
    protected $messageManager;

    /** @var  CustomerData |\PHPUnit\Framework\MockObject\MockObject */
    protected $customerData;

    /** @var  UrlInterface |\PHPUnit\Framework\MockObject\MockObject */
    protected $url;

    /** @var  TransportInterface |\PHPUnit\Framework\MockObject\MockObject */
    protected $transport;

    /** @var  EventManagerInterface |\PHPUnit\Framework\MockObject\MockObject */
    protected $eventManager;

    /** @var  CaptchaHelper |\PHPUnit\Framework\MockObject\MockObject */
    protected $captchaHelper;

    /** @var CaptchaModel |\PHPUnit\Framework\MockObject\MockObject */
    protected $captchaModel;

    /** @var Session |\PHPUnit\Framework\MockObject\MockObject */
    protected $customerSession;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function setUp(): void
    {
        $this->resultRedirect = $this->getMockBuilder(\Magento\Framework\Controller\Result\Redirect::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->resultLayout = $this->getMockBuilder(\Magento\Framework\View\Result\Layout::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->resultFactory = $this->getMockBuilder(\Magento\Framework\Controller\ResultFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->resultFactory->expects($this->any())
            ->method('create')
            ->willReturnMap([
                [ResultFactory::TYPE_REDIRECT, [], $this->resultRedirect],
                [ResultFactory::TYPE_LAYOUT, [], $this->resultLayout],
            ]);

        $this->request = $this->getMockBuilder(\Magento\Framework\App\RequestInterface::class)
            ->setMethods([
                'getPost',
                'getPostValue'
            ])
            ->getMockForAbstractClass();

        $this->messageManager = $this->getMockBuilder(\Magento\Framework\Message\ManagerInterface::class)
            ->getMockForAbstractClass();

        $this->url = $this->getMockBuilder(\Magento\Framework\UrlInterface::class)
            ->getMockForAbstractClass();

        $this->eventManager = $this->getMockBuilder(\Magento\Framework\Event\ManagerInterface::class)
            ->getMockForAbstractClass();

        $this->context = $this->getMockBuilder(\Magento\Framework\App\Action\Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->context->expects($this->any())
            ->method('getRequest')
            ->willReturn($this->request);
        $this->context->expects($this->any())
            ->method('getResultFactory')
            ->willReturn($this->resultFactory);
        $this->context->expects($this->any())
            ->method('getMessageManager')
            ->willReturn($this->messageManager);
        $this->context->expects($this->any())
            ->method('getUrl')
            ->willReturn($this->url);
        $this->context->expects($this->any())
            ->method('getEventManager')
            ->willReturn($this->eventManager);

        $this->formKeyValidator = $this->getMockBuilder(\Magento\Framework\Data\Form\FormKey\Validator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $customerMock = $this->getMockBuilder(\Magento\Customer\Model\Customer::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getEmail',
                'getId'
            ])
            ->getMock();

        $customerMock->expects($this->any())
            ->method('getEmail')
            ->willReturn('expamle@mail.com');

        $customerMock->expects($this->any())
            ->method('getId')
            ->willReturn(false);

        $this->customerSession = $this->getMockBuilder(\Magento\Customer\Model\Session::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getCustomer',
                'getData'
            ])
            ->getMock();

        $this->customerSession->expects($this->any())
            ->method('getCustomer')
            ->willReturn($customerMock);

        $this->customerSession->expects($this->any())
            ->method('getData')
            ->willReturn(false);

        $this->wishlistProvider = $this->getMockBuilder(\Magento\Wishlist\Controller\WishlistProviderInterface::class)
            ->getMockForAbstractClass();

        $this->captchaHelper = $this->getMockBuilder(CaptchaHelper::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'getCaptcha'
            ])
            ->getMock();

        $this->captchaModel = $this->getMockBuilder(CaptchaModel::class)
            ->disableOriginalConstructor()
            ->setMethods([
                'isRequired',
                'logAttempt'
            ])
            ->getMock();

        $objectHelper = new ObjectManager($this);

        $this->captchaHelper->expects($this->once())->method('getCaptcha')
            ->willReturn($this->captchaModel);
        $this->captchaModel->expects($this->any())->method('isRequired')
            ->willReturn(false);

        $this->model = $objectHelper->getObject(
            Send::class,
            [
                'context' => $this->context,
                'formKeyValidator' => $this->formKeyValidator,
                'wishlistProvider' => $this->wishlistProvider,
                'captchaHelper' => $this->captchaHelper,
                '_customerSession' => $this->customerSession
            ]
        );
    }

    public function testExecuteNoFormKeyValidated()
    {
        $this->formKeyValidator->expects($this->once())
            ->method('validate')
            ->with($this->request)
            ->willReturn(false);

        $this->resultRedirect->expects($this->once())
            ->method('setPath')
            ->with('*/*/')
            ->willReturnSelf();

        $this->assertEquals($this->resultRedirect, $this->model->execute());
    }

    /**
     */
    public function testExecuteNoWishlistAvailable()
    {
        $this->expectException(\Magento\Framework\Exception\NotFoundException::class);
        $this->expectExceptionMessage('Page not found.');

        $this->formKeyValidator->expects($this->once())
            ->method('validate')
            ->with($this->request)
            ->willReturn(true);

        $this->wishlistProvider->expects($this->once())
            ->method('getWishlist')
            ->willReturn(null);

        $this->model->execute();
    }
}
