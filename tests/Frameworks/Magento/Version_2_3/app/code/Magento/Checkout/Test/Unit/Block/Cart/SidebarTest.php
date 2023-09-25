<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Checkout\Test\Unit\Block\Cart;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class SidebarTest extends \PHPUnit\Framework\TestCase
{
    /** @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager  */
    protected $_objectManager;

    /**
     * @var \Magento\Checkout\Block\Cart\Sidebar
     */
    protected $model;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $layoutMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $urlBuilderMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $storeManagerMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $imageHelper;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $scopeConfigMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $checkoutSessionMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $requestMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $serializer;

    protected function setUp(): void
    {
        $this->_objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->requestMock = $this->createMock(\Magento\Framework\App\RequestInterface::class);
        $this->layoutMock = $this->createMock(\Magento\Framework\View\Layout::class);
        $this->checkoutSessionMock = $this->createMock(\Magento\Checkout\Model\Session::class);
        $this->urlBuilderMock = $this->createMock(\Magento\Framework\UrlInterface::class);
        $this->storeManagerMock = $this->createMock(\Magento\Store\Model\StoreManagerInterface::class);
        $this->imageHelper = $this->createMock(\Magento\Catalog\Helper\Image::class);
        $this->scopeConfigMock = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);

        $contextMock = $this->createPartialMock(
            \Magento\Framework\View\Element\Template\Context::class,
            ['getLayout', 'getUrlBuilder', 'getStoreManager', 'getScopeConfig', 'getRequest']
        );
        $contextMock->expects($this->once())
            ->method('getLayout')
            ->willReturn($this->layoutMock);
        $contextMock->expects($this->once())
            ->method('getUrlBuilder')
            ->willReturn($this->urlBuilderMock);
        $contextMock->expects($this->once())
            ->method('getStoreManager')
            ->willReturn($this->storeManagerMock);
        $contextMock->expects($this->once())
            ->method('getScopeConfig')
            ->willReturn($this->scopeConfigMock);
        $contextMock->expects($this->any())
            ->method('getRequest')
            ->willReturn($this->requestMock);

        $this->serializer = $this->createMock(\Magento\Framework\Serialize\Serializer\Json::class);

        $this->model = $this->_objectManager->getObject(
            \Magento\Checkout\Block\Cart\Sidebar::class,
            [
                'context' => $contextMock,
                'imageHelper' => $this->imageHelper,
                'checkoutSession' => $this->checkoutSessionMock,
                'serializer' => $this->serializer
            ]
        );
    }

    public function testGetTotalsHtml()
    {
        $totalsHtml = "$134.36";
        $totalsBlockMock = $this->getMockBuilder(\Magento\Checkout\Block\Shipping\Price::class)
            ->disableOriginalConstructor()
            ->setMethods(['toHtml'])
            ->getMock();

        $totalsBlockMock->expects($this->once())
            ->method('toHtml')
            ->willReturn($totalsHtml);

        $this->layoutMock->expects($this->once())
            ->method('getBlock')
            ->with('checkout.cart.minicart.totals')
            ->willReturn($totalsBlockMock);

        $this->assertEquals($totalsHtml, $this->model->getTotalsHtml());
    }

    public function testGetConfig()
    {
        $websiteId = 100;
        $storeMock = $this->createMock(\Magento\Store\Model\Store::class);

        $shoppingCartUrl = 'http://url.com/cart';
        $checkoutUrl = 'http://url.com/checkout';
        $updateItemQtyUrl = 'http://url.com/updateItemQty';
        $removeItemUrl = 'http://url.com/removeItem';
        $baseUrl = 'http://url.com/';
        $imageTemplate = 'Magento_Catalog/product/image_with_borders';

        $expectedResult = [
            'shoppingCartUrl' => $shoppingCartUrl,
            'checkoutUrl' => $checkoutUrl,
            'updateItemQtyUrl' => $updateItemQtyUrl,
            'removeItemUrl' => $removeItemUrl,
            'imageTemplate' => $imageTemplate,
            'baseUrl' => $baseUrl,
            'minicartMaxItemsVisible' => 3,
            'websiteId' => 100,
            'maxItemsToDisplay' => 8,
            'storeId' => null,
            'storeGroupId' => null
        ];

        $valueMap = [
            ['checkout/cart', [], $shoppingCartUrl],
            ['checkout', [], $checkoutUrl],
            ['checkout/sidebar/updateItemQty', ['_secure' => false], $updateItemQtyUrl],
            ['checkout/sidebar/removeItem', ['_secure' => false], $removeItemUrl]
        ];

        $this->requestMock->expects($this->any())
            ->method('isSecure')
            ->willReturn(false);

        $this->urlBuilderMock->expects($this->exactly(4))
            ->method('getUrl')
            ->willReturnMap($valueMap);
        $this->storeManagerMock->expects($this->any())->method('getStore')->willReturn($storeMock);
        $storeMock->expects($this->once())->method('getBaseUrl')->willReturn($baseUrl);

        $this->scopeConfigMock->expects($this->at(0))
            ->method('getValue')
            ->with(
                \Magento\Checkout\Block\Cart\Sidebar::XML_PATH_CHECKOUT_SIDEBAR_COUNT,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            )->willReturn(3);

        $this->scopeConfigMock->expects($this->at(1))
            ->method('getValue')
            ->with(
                'checkout/sidebar/max_items_display_count',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            )->willReturn(8);

        $storeMock->expects($this->once())->method('getWebsiteId')->willReturn($websiteId);

        $this->assertEquals($expectedResult, $this->model->getConfig());
    }

    public function testGetIsNeedToDisplaySideBar()
    {
        $this->scopeConfigMock->expects($this->once())
            ->method('getValue')
            ->with(
                \Magento\Checkout\Block\Cart\Sidebar::XML_PATH_CHECKOUT_SIDEBAR_DISPLAY,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            )->willReturn(true);

        $this->assertTrue($this->model->getIsNeedToDisplaySideBar());
    }

    public function testGetTotalsCache()
    {
        $quoteMock = $this->createMock(\Magento\Quote\Model\Quote::class);
        $totalsMock = ['totals'];
        $this->checkoutSessionMock->expects($this->once())->method('getQuote')->willReturn($quoteMock);
        $quoteMock->expects($this->once())->method('getTotals')->willReturn($totalsMock);

        $this->assertEquals($totalsMock, $this->model->getTotalsCache());
    }
}
