<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Wishlist\Test\Unit\Controller\Index;

use Magento\Store\Model\ScopeInterface;

/**
 * Test for wishlist plugin before dispatch
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PluginTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Customer\Model\Session|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $customerSession;

    /**
     * @var \Magento\Wishlist\Model\AuthenticationStateInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $authenticationState;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $config;

    /**
     * @var \Magento\Framework\App\Response\RedirectInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $redirector;

    /**
     * @var \Magento\Framework\Message\ManagerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $messageManager;

    /**
     * @var \Magento\Framework\App\Request\Http|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $request;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->customerSession = $this->getMockBuilder(\Magento\Customer\Model\Session::class)
            ->disableOriginalConstructor()
            ->setMethods(
                [
                    'authenticate',
                    'getBeforeWishlistUrl',
                    'setBeforeWishlistUrl',
                    'setBeforeWishlistRequest',
                    'getBeforeWishlistRequest',
                    'setBeforeRequestParams',
                    'setBeforeModuleName',
                    'setBeforeControllerName',
                    'setBeforeAction',
                ]
            )->getMock();

        $this->authenticationState = $this->createMock(\Magento\Wishlist\Model\AuthenticationState::class);
        $this->config = $this->createMock(\Magento\Framework\App\Config::class);
        $this->redirector = $this->createMock(\Magento\Store\App\Response\Redirect::class);
        $this->messageManager = $this->createMock(\Magento\Framework\Message\ManagerInterface::class);
        $this->request = $this->createMock(\Magento\Framework\App\Request\Http::class);
    }

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        unset(
            $this->customerSession,
            $this->authenticationState,
            $this->config,
            $this->redirector,
            $this->messageManager,
            $this->request
        );
    }

    /**
     * @return \Magento\Wishlist\Controller\Index\Plugin
     */
    protected function getPlugin()
    {
        return new \Magento\Wishlist\Controller\Index\Plugin(
            $this->customerSession,
            $this->authenticationState,
            $this->config,
            $this->redirector,
            $this->messageManager
        );
    }

    /**
     */
    public function testBeforeDispatch()
    {
        $this->expectException(\Magento\Framework\Exception\NotFoundException::class);

        $refererUrl = 'http://referer-url.com';
        $params = [
            'product' => 1,
            'login' => [],
        ];

        $actionFlag = $this->createMock(\Magento\Framework\App\ActionFlag::class);
        $indexController = $this->createMock(\Magento\Wishlist\Controller\Index\Index::class);

        $actionFlag
            ->expects($this->once())
            ->method('set')
            ->with('', 'no-dispatch', true)
            ->willReturn(true);

        $indexController
            ->expects($this->once())
            ->method('getActionFlag')
            ->willReturn($actionFlag);

        $this->authenticationState
            ->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);

        $this->redirector
            ->expects($this->once())
            ->method('getRefererUrl')
            ->willReturn($refererUrl);

        $this->request
            ->expects($this->once())
            ->method('getParams')
            ->willReturn($params);

        $this->customerSession->expects($this->once())
            ->method('authenticate')
            ->willReturn(false);
        $this->customerSession->expects($this->once())
            ->method('getBeforeWishlistUrl')
            ->willReturn(false);
        $this->customerSession->expects($this->once())
            ->method('setBeforeWishlistUrl')
            ->with($refererUrl)
            ->willReturnSelf();
        $this->customerSession->expects($this->once())
            ->method('setBeforeWishlistRequest')
            ->with(['product' => 1])
            ->willReturnSelf();
        $this->customerSession->expects($this->once())
            ->method('getBeforeWishlistRequest')
            ->willReturn($params);
        $this->customerSession->expects($this->once())
            ->method('setBeforeRequestParams')
            ->with($params)
            ->willReturnSelf();
        $this->customerSession->expects($this->once())
            ->method('setBeforeModuleName')
            ->with('wishlist')
            ->willReturnSelf();
        $this->customerSession->expects($this->once())
            ->method('setBeforeControllerName')
            ->with('index')
            ->willReturnSelf();
        $this->customerSession->expects($this->once())
            ->method('setBeforeAction')
            ->with('add')
            ->willReturnSelf();

        $this->config
            ->expects($this->once())
            ->method('isSetFlag')
            ->with('wishlist/general/active', ScopeInterface::SCOPE_STORES)
            ->willReturn(false);

        $this->getPlugin()->beforeDispatch($indexController, $this->request);
    }
}
