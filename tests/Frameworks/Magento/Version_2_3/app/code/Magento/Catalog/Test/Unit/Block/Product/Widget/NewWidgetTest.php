<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Catalog\Test\Unit\Block\Product\Widget;

use Magento\Catalog\Block\Product\Widget\NewWidget;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager as ObjectManagerHelper;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class NewWidgetTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Catalog\Block\Product\Widget\NewWidget|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $block;

    /**
     * @var \Magento\Framework\View\LayoutInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $layout;

    /**
     * @var \Magento\Framework\App\RequestInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $requestMock;

    /** @var \Magento\Backend\Block\Context|\PHPUnit\Framework\MockObject\MockObject */
    protected $context;

    /** @var ObjectManagerHelper */
    protected $objectManager;

    /** @var \Magento\Framework\Event\Manager|\PHPUnit\Framework\MockObject\MockObject */
    protected $eventManager;

    /** @var \Magento\Framework\App\Config|\PHPUnit\Framework\MockObject\MockObject */
    protected $scopeConfig;

    /** @var \Magento\Framework\App\Cache\State|\PHPUnit\Framework\MockObject\MockObject */
    protected $cacheState;

    /** @var \Magento\Catalog\Model\Config|\PHPUnit\Framework\MockObject\MockObject */
    protected $catalogConfig;

    /** @var \Magento\Framework\Stdlib\DateTime\Timezone|\PHPUnit\Framework\MockObject\MockObject */
    protected $localDate;

    /** @var \Magento\Catalog\Model\ResourceModel\Product\Collection|\PHPUnit\Framework\MockObject\MockObject */
    protected $productCollection;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->objectManager = new ObjectManagerHelper($this);
        $this->eventManager = $this->createPartialMock(\Magento\Framework\Event\Manager::class, ['dispatch']);
        $this->scopeConfig = $this->createMock(\Magento\Framework\App\Config::class);
        $this->cacheState = $this->createPartialMock(\Magento\Framework\App\Cache\State::class, ['isEnabled']);
        $this->localDate = $this->createMock(\Magento\Framework\Stdlib\DateTime\Timezone::class);
        $this->catalogConfig = $this->getMockBuilder(\Magento\Catalog\Model\Config::class)
            ->setMethods(['getProductAttributes'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->layout = $this->createMock(\Magento\Framework\View\Layout::class);
        $this->requestMock = $this->getMockBuilder(\Magento\Framework\App\RequestInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->context = $this->getMockBuilder(\Magento\Catalog\Block\Product\Context::class)
            ->setMethods(
                [
                    'getEventManager', 'getScopeConfig', 'getLayout',
                    'getRequest', 'getCacheState', 'getCatalogConfig',
                    'getLocaleDate'
                ]
            )
            ->disableOriginalConstructor()
            ->disableArgumentCloning()
            ->getMock();

        $this->context->expects($this->any())
            ->method('getLayout')
            ->willReturn($this->layout);
        $this->context->expects($this->any())
            ->method('getRequest')
            ->willReturn($this->requestMock);

        $this->block = $this->objectManager->getObject(
            \Magento\Catalog\Block\Product\Widget\NewWidget::class,
            [
                'context' => $this->context
            ]
        );
    }

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        $this->block = null;
    }

    public function testGetProductPriceHtml()
    {
        $id = 6;
        $expectedHtml = '
        <div class="price-box price-final_price">
            <span class="regular-price" id="product-price-' . $id . '">
                <span class="price">$0.00</span>
            </span>
        </div>';
        $type = 'widget-new-list';
        $productMock = $this->createPartialMock(\Magento\Catalog\Model\Product::class, ['getId']);
        $productMock->expects($this->once())
            ->method('getId')
            ->willReturn($id);
        $arguments = [
            'price_id' => 'old-price-' . $id . '-' . $type,
            'display_minimal_price' => true,
            'include_container' => true,
            'zone' => \Magento\Framework\Pricing\Render::ZONE_ITEM_LIST,
        ];

        $priceBoxMock = $this->createPartialMock(\Magento\Framework\Pricing\Render::class, ['render']);

        $this->layout->expects($this->once())
            ->method('getBlock')
            ->with($this->equalTo('product.price.render.default'))
            ->willReturn($priceBoxMock);

        $priceBoxMock->expects($this->once())
            ->method('render')
            ->with($this->equalTo('final_price'), $this->equalTo($productMock), $this->equalTo($arguments))
            ->willReturn($expectedHtml);

        $result = $this->block->getProductPriceHtml($productMock, $type);
        $this->assertEquals($expectedHtml, $result);
    }

    /**
     * @param int $pageNumber
     * @param int $expectedResult
     * @dataProvider getCurrentPageDataProvider
     */
    public function testGetCurrentPage($pageNumber, $expectedResult)
    {
        $this->block->setData('page_var_name', 'page_number');

        $this->requestMock->expects($this->any())
            ->method('getParam')
            ->with('page_number')
            ->willReturn($pageNumber);

        $this->assertEquals($expectedResult, $this->block->getCurrentPage());
    }

    /**
     * @return array
     */
    public function getCurrentPageDataProvider()
    {
        return [
            [1, 1],
            [5, 5],
            [10, 10]
        ];
    }

    public function testGetProductsCount()
    {
        $this->assertEquals(10, $this->block->getProductsCount());
        $this->block->setProductsCount(2);
        $this->assertEquals(2, $this->block->getProductsCount());
    }

    /**
     * @return void
     */
    protected function generalGetProductCollection()
    {
        $this->eventManager->expects($this->exactly(2))->method('dispatch')
            ->willReturn(true);
        $this->scopeConfig->expects($this->once())->method('getValue')->withAnyParameters()
            ->willReturn(false);
        $this->cacheState->expects($this->atLeastOnce())->method('isEnabled')->withAnyParameters()
            ->willReturn(false);
        $this->catalogConfig->expects($this->once())->method('getProductAttributes')
            ->willReturn([]);
        $this->localDate->expects($this->any())->method('date')
            ->willReturn(new \DateTime('now', new \DateTimeZone('UTC')));

        $this->context->expects($this->once())->method('getEventManager')->willReturn($this->eventManager);
        $this->context->expects($this->once())->method('getScopeConfig')->willReturn($this->scopeConfig);
        $this->context->expects($this->once())->method('getCacheState')->willReturn($this->cacheState);
        $this->context->expects($this->once())->method('getCatalogConfig')->willReturn($this->catalogConfig);
        $this->context->expects($this->once())->method('getLocaleDate')->willReturn($this->localDate);

        $this->productCollection = $this->getMockBuilder(\Magento\Catalog\Model\ResourceModel\Product\Collection::class)
            ->setMethods(
                [
                    'setVisibility', 'addMinimalPrice', 'addFinalPrice',
                    'addTaxPercents', 'addAttributeToSelect', 'addUrlRewrite',
                    'addStoreFilter', 'addAttributeToSort', 'setPageSize',
                    'setCurPage', 'addAttributeToFilter'
                ]
            )
            ->disableOriginalConstructor()
            ->getMock();
        $this->productCollection->expects($this->once())->method('setVisibility')
            ->willReturnSelf();
        $this->productCollection->expects($this->once())->method('addMinimalPrice')
            ->willReturnSelf();
        $this->productCollection->expects($this->once())->method('addFinalPrice')
            ->willReturnSelf();
        $this->productCollection->expects($this->once())->method('addTaxPercents')
            ->willReturnSelf();
        $this->productCollection->expects($this->once())->method('addAttributeToSelect')
            ->willReturnSelf();
        $this->productCollection->expects($this->once())->method('addUrlRewrite')
            ->willReturnSelf();
        $this->productCollection->expects($this->once())->method('addStoreFilter')
            ->willReturnSelf();
        $this->productCollection->expects($this->once())->method('addAttributeToSort')
            ->willReturnSelf();
        $this->productCollection->expects($this->atLeastOnce())->method('setCurPage')
            ->willReturnSelf();
        $this->productCollection->expects($this->any())->method('addAttributeToFilter')
            ->willReturnSelf();
    }

    /**
     * @param string $displayType
     * @param bool $pagerEnable
     * @param int $productsCount
     * @param int $productsPerPage
     */
    protected function startTestGetProductCollection($displayType, $pagerEnable, $productsCount, $productsPerPage)
    {
        $productCollectionFactory = $this->createPartialMock(
            \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory::class,
            ['create']
        );
        $productCollectionFactory->expects($this->atLeastOnce())->method('create')
            ->willReturn($this->productCollection);

        $this->block = $this->objectManager->getObject(
            \Magento\Catalog\Block\Product\Widget\NewWidget::class,
            [
                'context' => $this->context,
                'productCollectionFactory' => $productCollectionFactory
            ]
        );

        if (null === $productsPerPage) {
            $this->block->unsetData('products_per_page');
        } else {
            $this->block->setData('products_per_page', $productsPerPage);
        }

        $this->block->setData('show_pager', $pagerEnable);
        $this->block->setData('display_type', $displayType);
        $this->block->setProductsCount($productsCount);
        $this->block->toHtml();
    }

    /**
     * Test protected `_getProductCollection` and `getPageSize` methods via public `toHtml` method,
     * for display_type == DISPLAY_TYPE_NEW_PRODUCTS.
     *
     * @param bool $pagerEnable
     * @param int $productsCount
     * @param int $productsPerPage
     * @param int $expectedPageSize
     * @dataProvider getProductCollectionDataProvider
     */
    public function testGetProductNewCollection($pagerEnable, $productsCount, $productsPerPage, $expectedPageSize)
    {
        $this->generalGetProductCollection();

        $this->productCollection->expects($this->exactly(2))->method('setPageSize')
            ->withConsecutive(
                [$productsCount],
                [$expectedPageSize]
            )
            ->willReturnSelf();

        $this->startTestGetProductCollection(
            NewWidget::DISPLAY_TYPE_NEW_PRODUCTS,
            $pagerEnable,
            $productsCount,
            $productsPerPage
        );
    }

    /**
     * Test protected `_getProductCollection` and `getPageSize` methods via public `toHtml` method,
     * for display_type == DISPLAY_TYPE_ALL_PRODUCTS.
     *
     * @param bool $pagerEnable
     * @param int $productsCount
     * @param int $productsPerPage
     * @param int $expectedPageSize
     * @dataProvider getProductCollectionDataProvider
     */
    public function testGetProductAllCollection($pagerEnable, $productsCount, $productsPerPage, $expectedPageSize)
    {
        $this->generalGetProductCollection();

        $this->productCollection->expects($this->atLeastOnce())->method('setPageSize')->with($expectedPageSize)
            ->willReturnSelf();

        $this->startTestGetProductCollection(
            NewWidget::DISPLAY_TYPE_ALL_PRODUCTS,
            $pagerEnable,
            $productsCount,
            $productsPerPage
        );
    }

    /**
     * @return array
     */
    public function getProductCollectionDataProvider()
    {
        return [
            [true, 1, null, 5],
            [true, 5, null, 5],
            [true, 10, null, 5],
            [true, 1, 2, 2],
            [true, 5, 3, 3],
            [true, 10, 7, 7],
            [false, 1, null, 1],
            [false, 3, null, 3],
            [false, 5, null, 5],
            [false, 1, 3, 1],
            [false, 3, 5, 3],
            [false, 5, 10, 5]
        ];
    }
}
