<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Multishipping\Test\Unit\Controller\Checkout\Address;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class NewShippingTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Multishipping\Controller\Checkout\Address\NewShipping
     */
    protected $controller;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $configMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $objectManagerMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $stateMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $viewMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $layoutMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $addressFormMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $urlMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $pageMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $titleMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $checkoutMock;

    protected function setUp(): void
    {
        $objectManager = new ObjectManager($this);
        $this->configMock = $this->createMock(\Magento\Framework\View\Page\Config::class);
        $this->checkoutMock =
            $this->createMock(\Magento\Multishipping\Model\Checkout\Type\Multishipping::class);
        $this->titleMock = $this->createMock(\Magento\Framework\View\Page\Title::class);
        $this->layoutMock = $this->createMock(\Magento\Framework\View\Layout::class);
        $this->viewMock = $this->createMock(\Magento\Framework\App\ViewInterface::class);
        $this->objectManagerMock = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $this->stateMock =
            $this->createMock(\Magento\Multishipping\Model\Checkout\Type\Multishipping\State::class);
        $valueMap = [
            [\Magento\Multishipping\Model\Checkout\Type\Multishipping\State::class, $this->stateMock],
            [\Magento\Multishipping\Model\Checkout\Type\Multishipping::class, $this->checkoutMock]
        ];
        $this->objectManagerMock->expects($this->any())->method('get')->willReturnMap($valueMap);
        $request = $this->getMockBuilder(\Magento\Framework\App\RequestInterface::class)
            ->disableOriginalConstructor()
            ->setMethods([])
            ->getMockForAbstractClass();
        $response = $this->getMockBuilder(\Magento\Framework\App\ResponseInterface::class)
            ->disableOriginalConstructor()
            ->setMethods([])
            ->getMockForAbstractClass();
        $contextMock = $this->createMock(\Magento\Framework\App\Action\Context::class);
        $contextMock->expects($this->atLeastOnce())
            ->method('getRequest')
            ->willReturn($request);
        $contextMock->expects($this->atLeastOnce())
            ->method('getResponse')
            ->willReturn($response);
        $contextMock->expects($this->any())->method('getView')->willReturn($this->viewMock);
        $contextMock->expects($this->any())->method('getObjectManager')->willReturn($this->objectManagerMock);
        $methods = ['setTitle', 'getTitle', 'setSuccessUrl', 'setBackUrl', 'setErrorUrl', '__wakeUp'];
        $this->addressFormMock =
            $this->createPartialMock(\Magento\Customer\Block\Address\Edit::class, $methods);
        $this->urlMock = $this->createMock(\Magento\Framework\UrlInterface::class);
        $contextMock->expects($this->any())->method('getUrl')->willReturn($this->urlMock);
        $this->pageMock = $this->createMock(\Magento\Framework\View\Result\Page::class);
        $this->pageMock->expects($this->any())->method('getConfig')->willReturn($this->configMock);
        $this->configMock->expects($this->any())->method('getTitle')->willReturn($this->titleMock);
        $this->viewMock->expects($this->any())->method('getPage')->willReturn($this->pageMock);
        $this->controller = $objectManager->getObject(
            \Magento\Multishipping\Controller\Checkout\Address\NewShipping::class,
            ['context' => $contextMock]
        );
    }

    /**
     * @param string $backUrl
     * @param string $shippingAddress
     * @param string $url
     * @dataProvider executeDataProvider
     */
    public function testExecute($backUrl, $shippingAddress, $url)
    {
        $this->stateMock
            ->expects($this->once())
            ->method('setActiveStep')
            ->with(\Magento\Multishipping\Model\Checkout\Type\Multishipping\State::STEP_SELECT_ADDRESSES);
        $this->viewMock->expects($this->once())->method('loadLayout')->willReturnSelf();
        $this->viewMock->expects($this->any())->method('getLayout')->willReturn($this->layoutMock);
        $this->layoutMock
            ->expects($this->once())
            ->method('getBlock')
            ->with('customer_address_edit')
            ->willReturn($this->addressFormMock);
        $this->addressFormMock
            ->expects($this->once())
            ->method('setTitle')
            ->with('Create Shipping Address')
            ->willReturnSelf();
        $helperMock = $this->createPartialMock(\Magento\Multishipping\Helper\Data::class, ['__']);
        $helperMock->expects($this->any())->method('__')->willReturn('Create Shipping Address');
        $this->addressFormMock->expects($this->once())->method('setSuccessUrl')->with('success/url')->willReturnSelf();
        $this->addressFormMock->expects($this->once())->method('setErrorUrl')->with('error/url')->willReturnSelf();
        $valueMap = [
            ['*/*/shippingSaved', null, 'success/url'],
            ['*/*/*', null, 'error/url'],
            [$backUrl, null, $url]
        ];
        $this->urlMock->expects($this->any())->method('getUrl')->willReturnMap($valueMap);
        $this->titleMock->expects($this->once())->method('getDefault')->willReturn('default_title');
        $this->addressFormMock->expects($this->once())->method('getTitle')->willReturn('Address title');
        $this->titleMock->expects($this->once())->method('set')->with('Address title - default_title');
        $this->checkoutMock
            ->expects($this->once())
            ->method('getCustomerDefaultShippingAddress')
            ->willReturn($shippingAddress);
        $this->addressFormMock->expects($this->once())->method('setBackUrl')->with($url);
        $this->viewMock->expects($this->once())->method('renderLayout');
        $this->controller->execute();
    }

    /**
     * @return array
     */
    public function executeDataProvider()
    {
        return [
            'shipping_address_exists' => ['*/checkout/addresses', 'shipping_address', 'back/address'],
            'shipping_address_not_exist' => ['checkout/cart/', null, 'back/cart']
        ];
    }

    public function testExecuteWhenCustomerAddressBlockNotExist()
    {
        $this->stateMock
            ->expects($this->once())
            ->method('setActiveStep')
            ->with(\Magento\Multishipping\Model\Checkout\Type\Multishipping\State::STEP_SELECT_ADDRESSES);
        $this->viewMock->expects($this->once())->method('loadLayout')->willReturnSelf();
        $this->viewMock->expects($this->any())->method('getLayout')->willReturn($this->layoutMock);
        $this->layoutMock
            ->expects($this->once())
            ->method('getBlock')
            ->with('customer_address_edit');
        $this->urlMock->expects($this->never())->method('getUrl');
        $this->viewMock->expects($this->once())->method('renderLayout');
        $this->controller->execute();
    }
}
