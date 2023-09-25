<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Wishlist\Test\Unit\Model\Rss;

use Magento\Directory\Helper\Data;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class WishlistTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Wishlist\Model\Rss\Wishlist
     */
    protected $model;

    /**
     * @var \Magento\Wishlist\Block\Customer\Wishlist
     */
    protected $wishlistBlock;

    /**
     * @var \Magento\Rss\Model\RssFactory
     */
    protected $rssFactoryMock;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $urlBuilderMock;

    /**
     * @var \Magento\Wishlist\Helper\Rss
     */
    protected $wishlistHelperMock;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Magento\Catalog\Helper\Image
     */
    protected $imageHelperMock;

    /**
     * @var \Magento\Catalog\Helper\Output
     */
    protected $catalogOutputMock;

    /**
     * @var \Magento\Catalog\Helper\Output|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $layoutMock;

    /**
     * @var \Magento\Customer\Model\CustomerFactory
     */
    protected $customerFactory;

    /**
     * Set up mock objects for tested class
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->catalogOutputMock = $this->createMock(\Magento\Catalog\Helper\Output::class);
        $this->rssFactoryMock = $this->createPartialMock(\Magento\Rss\Model\RssFactory::class, ['create']);
        $this->wishlistBlock = $this->createMock(\Magento\Wishlist\Block\Customer\Wishlist::class);
        $this->wishlistHelperMock = $this->createPartialMock(
            \Magento\Wishlist\Helper\Rss::class,
            ['getWishlist', 'getCustomer', 'getCustomerName']
        );
        $this->urlBuilderMock = $this->getMockForAbstractClass(\Magento\Framework\UrlInterface::class);
        $this->scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);

        $this->imageHelperMock = $this->createMock(\Magento\Catalog\Helper\Image::class);

        $this->layoutMock = $this->getMockForAbstractClass(
            \Magento\Framework\View\LayoutInterface::class,
            [],
            '',
            true,
            true,
            true,
            ['getBlock']
        );

        $this->customerFactory = $this->getMockBuilder(\Magento\Customer\Model\CustomerFactory::class)
            ->setMethods(['create'])->disableOriginalConstructor()->getMock();

        $requestMock = $this->createMock(\Magento\Framework\App\RequestInterface::class);
        $requestMock->expects($this->any())->method('getParam')->with('sharing_code')
            ->willReturn('somesharingcode');

        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->model = $objectManager->getObject(
            \Magento\Wishlist\Model\Rss\Wishlist::class,
            [
                'wishlistHelper' => $this->wishlistHelperMock,
                'wishlistBlock' => $this->wishlistBlock,
                'outputHelper' => $this->catalogOutputMock,
                'imageHelper' => $this->imageHelperMock,
                'urlBuilder' => $this->urlBuilderMock,
                'scopeConfig' => $this->scopeConfig,
                'rssFactory' => $this->rssFactoryMock,
                'layout' => $this->layoutMock,
                'request' => $requestMock,
                'customerFactory' => $this->customerFactory
            ]
        );
    }

    public function testGetRssData()
    {
        $wishlistId = 1;
        $customerName = 'Customer Name';
        $title = "$customerName's Wishlist";
        $wishlistModelMock = $this->createPartialMock(
            \Magento\Wishlist\Model\Wishlist::class,
            ['getId', '__wakeup', 'getCustomerId', 'getItemCollection', 'getSharingCode']
        );
        $customerServiceMock = $this->createMock(\Magento\Customer\Api\Data\CustomerInterface::class);
        $wishlistSharingUrl = 'wishlist/shared/index/1';
        $locale = 'en_US';
        $productUrl = 'http://product.url/';
        $productName = 'Product name';

        $customer = $this->getMockBuilder(\Magento\Customer\Model\Customer::class)
            ->setMethods(['getName', '__wakeup', 'load'])
            ->disableOriginalConstructor()->getMock();
        $customer->expects($this->once())->method('load')->willReturnSelf();
        $customer->expects($this->once())->method('getName')->willReturn('Customer Name');

        $this->customerFactory->expects($this->once())->method('create')->willReturn($customer);

        $this->wishlistHelperMock->expects($this->any())
            ->method('getWishlist')
            ->willReturn($wishlistModelMock);
        $this->wishlistHelperMock->expects($this->any())
            ->method('getCustomer')
            ->willReturn($customerServiceMock);
        $wishlistModelMock->expects($this->once())
            ->method('getId')
            ->willReturn($wishlistId);
        $this->urlBuilderMock->expects($this->once())
            ->method('getUrl')
            ->willReturn($wishlistSharingUrl);
        $this->scopeConfig->expects($this->any())
            ->method('getValue')
            ->willReturnMap(
                
                    [
                        [
                            'advanced/modules_disable_output/Magento_Rss',
                            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                            null,
                            null,
                        ],
                        [
                            Data::XML_PATH_DEFAULT_LOCALE,
                            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                            null,
                            $locale
                        ],
                    ]
                
            );

        $staticArgs = [
            'productName' => $productName,
            'productUrl' => $productUrl,
        ];
        $description = $this->processWishlistItemDescription($wishlistModelMock, $staticArgs);

        $expectedResult = [
            'title' => $title,
            'description' => $title,
            'link' => $wishlistSharingUrl,
            'charset' => 'UTF-8',
            'entries' => [
                0 => [
                    'title' => $productName,
                    'link' => $productUrl,
                    'description' => $description,
                ],
            ],
        ];

        $this->assertEquals($expectedResult, $this->model->getRssData());
    }

    /**
     * Additional function to process forming description for wishlist item
     *
     * @param \Magento\Wishlist\Model\Wishlist $wishlistModelMock
     * @param array $staticArgs
     * @return string
     */
    protected function processWishlistItemDescription($wishlistModelMock, $staticArgs)
    {
        $imgThumbSrc = 'http://source-for-thumbnail';
        $priceHtmlForTest = '<div class="price">Price is 10 for example</div>';
        $productDescription = 'Product description';
        $productShortDescription = 'Product short description';

        $wishlistItem = $this->createMock(\Magento\Wishlist\Model\Item::class);
        $wishlistItemsCollection = [
            $wishlistItem,
        ];
        $productMock = $this->createPartialMock(\Magento\Catalog\Model\Product::class, [
                'getAllowedInRss',
                'getAllowedPriceInRss',
                'getDescription',
                'getShortDescription',
                'getName',
                '__wakeup'
            ]);

        $wishlistModelMock->expects($this->once())
            ->method('getItemCollection')
            ->willReturn($wishlistItemsCollection);
        $wishlistItem->expects($this->once())
            ->method('getProduct')
            ->willReturn($productMock);
        $productMock->expects($this->once())
            ->method('getAllowedPriceInRss')
            ->willReturn(true);
        $productMock->expects($this->once())
            ->method('getName')
            ->willReturn($staticArgs['productName']);
        $productMock->expects($this->once())
            ->method('getAllowedInRss')
            ->willReturn(true);
        $this->imageHelperMock->expects($this->once())
            ->method('init')
            ->with($productMock, 'rss_thumbnail')
            ->willReturnSelf();
        $this->imageHelperMock->expects($this->once())
            ->method('getUrl')
            ->willReturn($imgThumbSrc);
        $priceRendererMock = $this->createPartialMock(\Magento\Framework\Pricing\Render::class, ['render']);

        $this->layoutMock->expects($this->once())
            ->method('getBlock')
            ->willReturn($priceRendererMock);
        $priceRendererMock->expects($this->once())
            ->method('render')
            ->willReturn($priceHtmlForTest);
        $productMock->expects($this->any())
            ->method('getDescription')
            ->willReturn($productDescription);
        $productMock->expects($this->any())
            ->method('getShortDescription')
            ->willReturn($productShortDescription);
        $this->catalogOutputMock->expects($this->any())
            ->method('productAttribute')
            ->willReturnArgument(1);
        $this->wishlistBlock
            ->expects($this->any())
            ->method('getProductUrl')
            ->with($productMock, ['_rss' => true])
            ->willReturn($staticArgs['productUrl']);

        $description = '<table><tr><td><a href="' . $staticArgs['productUrl'] . '"><img src="' . $imgThumbSrc .
            '" border="0" align="left" height="75" width="75"></a></td><td style="text-decoration:none;">' .
            $productShortDescription . '<p>' . $priceHtmlForTest . '</p><p>Comment: ' . $productDescription . '<p>' .
            '</td></tr></table>';

        return $description;
    }

    public function testIsAllowed()
    {
        $customerId = 1;
        $customerServiceMock = $this->createMock(\Magento\Customer\Api\Data\CustomerInterface::class);
        $wishlist = $this->getMockBuilder(\Magento\Wishlist\Model\Wishlist::class)->setMethods(
            ['getId', '__wakeup', 'getCustomerId', 'getItemCollection', 'getSharingCode']
        )->disableOriginalConstructor()->getMock();
        $wishlist->expects($this->once())->method('getCustomerId')->willReturn($customerId);
        $this->wishlistHelperMock->expects($this->any())->method('getWishlist')
            ->willReturn($wishlist);
        $this->wishlistHelperMock->expects($this->any())
            ->method('getCustomer')
            ->willReturn($customerServiceMock);
        $customerServiceMock->expects($this->once())->method('getId')->willReturn($customerId);
        $this->scopeConfig->expects($this->once())->method('isSetFlag')
            ->with('rss/wishlist/active', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn(true);

        $this->assertTrue($this->model->isAllowed());
    }

    public function testGetCacheKey()
    {
        $wishlistId = 1;
        $wishlist = $this->getMockBuilder(\Magento\Wishlist\Model\Wishlist::class)->setMethods(
            ['getId', '__wakeup', 'getCustomerId', 'getItemCollection', 'getSharingCode']
        )->disableOriginalConstructor()->getMock();
        $wishlist->expects($this->once())->method('getId')->willReturn($wishlistId);
        $this->wishlistHelperMock->expects($this->any())->method('getWishlist')
            ->willReturn($wishlist);
        $this->assertEquals('rss_wishlist_data_1', $this->model->getCacheKey());
    }

    public function testGetCacheLifetime()
    {
        $this->assertEquals(60, $this->model->getCacheLifetime());
    }

    public function testIsAuthRequired()
    {
        $wishlist = $this->getMockBuilder(\Magento\Wishlist\Model\Wishlist::class)->setMethods(
            ['getId', '__wakeup', 'getCustomerId', 'getItemCollection', 'getSharingCode']
        )->disableOriginalConstructor()->getMock();
        $wishlist->expects($this->any())->method('getSharingCode')
            ->willReturn('somesharingcode');
        $this->wishlistHelperMock->expects($this->any())->method('getWishlist')
            ->willReturn($wishlist);
        $this->assertFalse($this->model->isAuthRequired());
    }

    public function testGetProductPriceHtmlBlockDoesntExists()
    {
        $price = 10.;

        $productMock = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)
            ->disableOriginalConstructor()
            ->getMock();

        $renderBlockMock = $this->getMockBuilder(\Magento\Framework\Pricing\Render::class)
            ->disableOriginalConstructor()
            ->getMock();
        $renderBlockMock->expects($this->once())
            ->method('render')
            ->with(
                'wishlist_configured_price',
                $productMock,
                ['zone' => \Magento\Framework\Pricing\Render::ZONE_ITEM_LIST]
            )
            ->willReturn($price);

        $this->layoutMock->expects($this->once())
            ->method('getBlock')
            ->with('product.price.render.default')
            ->willReturn(false);
        $this->layoutMock->expects($this->once())
            ->method('createBlock')
            ->with(
                \Magento\Framework\Pricing\Render::class,
                'product.price.render.default',
                ['data' => ['price_render_handle' => 'catalog_product_prices']]
            )
            ->willReturn($renderBlockMock);

        $this->assertEquals($price, $this->model->getProductPriceHtml($productMock));
    }

    public function testGetProductPriceHtmlBlockExists()
    {
        $price = 10.;

        $productMock = $this->getMockBuilder(\Magento\Catalog\Model\Product::class)
            ->disableOriginalConstructor()
            ->getMock();

        $renderBlockMock = $this->getMockBuilder(\Magento\Framework\Pricing\Render::class)
            ->disableOriginalConstructor()
            ->getMock();
        $renderBlockMock->expects($this->once())
            ->method('render')
            ->with(
                'wishlist_configured_price',
                $productMock,
                ['zone' => \Magento\Framework\Pricing\Render::ZONE_ITEM_LIST]
            )
            ->willReturn($price);

        $this->layoutMock->expects($this->once())
            ->method('getBlock')
            ->with('product.price.render.default')
            ->willReturn($renderBlockMock);

        $this->assertEquals($price, $this->model->getProductPriceHtml($productMock));
    }
}
