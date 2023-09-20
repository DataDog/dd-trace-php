<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\Catalog\Test\Unit\Pricing\Render;

use Magento\Catalog\Model\Product\Pricing\Renderer\SalableResolverInterface;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Catalog\Pricing\Price\MinimalPriceCalculatorInterface;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\Render\Amount;

/**
 * Class FinalPriceBoxTest
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class FinalPriceBoxTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Catalog\Pricing\Render\FinalPriceBox
     */
    protected $object;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $priceType;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $priceInfo;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $priceBox;

    /**
     * @var \Magento\Framework\View\LayoutInterface | \PHPUnit\Framework\MockObject\MockObject
     */
    protected $layout;

    /**
     * @var \Magento\Catalog\Model\Product|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $product;

    /**
     * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $logger;

    /**
     * @var \Magento\Framework\Pricing\Render\RendererPool|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $rendererPool;

    /**
     * @var \Magento\Framework\Pricing\Price\PriceInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $price;

    /**
     * @var SalableResolverInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $salableResolverMock;

    /**
     * @var MinimalPriceCalculatorInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $minimalPriceCalculator;

    protected function setUp(): void
    {
        $this->product = $this->createPartialMock(
            \Magento\Catalog\Model\Product::class,
            ['getPriceInfo', '__wakeup', 'getCanShowPrice', 'isSalable', 'getId']
        );
        $this->priceInfo = $this->createMock(\Magento\Framework\Pricing\PriceInfoInterface::class);
        $this->product->expects($this->any())
            ->method('getPriceInfo')
            ->willReturn($this->priceInfo);

        $eventManager = $this->createMock(\Magento\Framework\Event\Test\Unit\ManagerStub::class);
        $this->layout = $this->createMock(\Magento\Framework\View\Layout::class);

        $this->priceBox = $this->createMock(\Magento\Framework\Pricing\Render\PriceBox::class);
        $this->logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $this->layout->expects($this->any())->method('getBlock')->willReturn($this->priceBox);

        $cacheState = $this->getMockBuilder(\Magento\Framework\App\Cache\StateInterface::class)
            ->getMockForAbstractClass();

        $appState = $this->getMockBuilder(\Magento\Framework\App\State::class)
            ->disableOriginalConstructor()
            ->getMock();

        $resolver = $this->getMockBuilder(\Magento\Framework\View\Element\Template\File\Resolver::class)
            ->disableOriginalConstructor()
            ->getMock();

        $urlBuilder = $this->getMockBuilder(\Magento\Framework\UrlInterface::class)->getMockForAbstractClass();

        $store = $this->getMockBuilder(\Magento\Store\Api\Data\StoreInterface::class)->getMockForAbstractClass();
        $storeManager = $this->getMockBuilder(\Magento\Store\Model\StoreManagerInterface::class)
            ->setMethods(['getStore', 'getCode'])
            ->getMockForAbstractClass();
        $storeManager->expects($this->any())->method('getStore')->willReturn($store);

        $scopeConfigMock = $this->getMockForAbstractClass(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $context = $this->createMock(\Magento\Framework\View\Element\Template\Context::class);
        $context->expects($this->any())
            ->method('getEventManager')
            ->willReturn($eventManager);
        $context->expects($this->any())
            ->method('getLayout')
            ->willReturn($this->layout);
        $context->expects($this->any())
            ->method('getLogger')
            ->willReturn($this->logger);
        $context->expects($this->any())
            ->method('getScopeConfig')
            ->willReturn($scopeConfigMock);
        $context->expects($this->any())
            ->method('getCacheState')
            ->willReturn($cacheState);
        $context->expects($this->any())
            ->method('getStoreManager')
            ->willReturn($storeManager);
        $context->expects($this->any())
            ->method('getAppState')
            ->willReturn($appState);
        $context->expects($this->any())
            ->method('getResolver')
            ->willReturn($resolver);
        $context->expects($this->any())
            ->method('getUrlBuilder')
            ->willReturn($urlBuilder);

        $this->rendererPool = $this->getMockBuilder(\Magento\Framework\Pricing\Render\RendererPool::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->price = $this->createMock(\Magento\Framework\Pricing\Price\PriceInterface::class);
        $this->price->expects($this->any())
            ->method('getPriceCode')
            ->willReturn(\Magento\Catalog\Pricing\Price\FinalPrice::PRICE_CODE);

        $objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->salableResolverMock = $this->getMockBuilder(SalableResolverInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->minimalPriceCalculator = $this->getMockForAbstractClass(MinimalPriceCalculatorInterface::class);
        $this->object = $objectManager->getObject(
            \Magento\Catalog\Pricing\Render\FinalPriceBox::class,
            [
                'context' => $context,
                'saleableItem' => $this->product,
                'rendererPool' => $this->rendererPool,
                'price' => $this->price,
                'data' => ['zone' => 'test_zone', 'list_category_page' => true],
                'salableResolver' => $this->salableResolverMock,
                'minimalPriceCalculator' => $this->minimalPriceCalculator
            ]
        );
    }

    public function testRenderMsrpDisabled()
    {
        $priceType = $this->createMock(\Magento\Msrp\Pricing\Price\MsrpPrice::class);
        $this->priceInfo->expects($this->once())
            ->method('getPrice')
            ->with($this->equalTo('msrp_price'))
            ->willReturn($priceType);

        $priceType->expects($this->any())
            ->method('canApplyMsrp')
            ->with($this->equalTo($this->product))
            ->willReturn(false);

        $this->salableResolverMock->expects($this->once())->method('isSalable')->with($this->product)->willReturn(true);

        $result = $this->object->toHtml();

        //assert price wrapper
        $this->assertStringStartsWith('<div', $result);
        //assert css_selector
        $this->assertMatchesRegularExpression('/[final_price]/', $result);
    }

    public function testNotSalableItem()
    {
        $this->salableResolverMock
            ->expects($this->once())
            ->method('isSalable')
            ->with($this->product)
            ->willReturn(false);
        $result = $this->object->toHtml();

        $this->assertEmpty($result);
    }

    public function testRenderMsrpEnabled()
    {
        $priceType = $this->createMock(\Magento\Msrp\Pricing\Price\MsrpPrice::class);
        $this->priceInfo->expects($this->once())
            ->method('getPrice')
            ->with($this->equalTo('msrp_price'))
            ->willReturn($priceType);

        $priceType->expects($this->any())
            ->method('canApplyMsrp')
            ->with($this->equalTo($this->product))
            ->willReturn(true);

        $priceType->expects($this->any())
            ->method('isMinimalPriceLessMsrp')
            ->with($this->equalTo($this->product))
            ->willReturn(true);

        $priceBoxRender = $this->getMockBuilder(\Magento\Framework\Pricing\Render\PriceBox::class)
            ->disableOriginalConstructor()
            ->getMock();
        $priceBoxRender->expects($this->once())
            ->method('toHtml')
            ->willReturn('test');

        $arguments = [
            'real_price_html' => '',
            'zone' => 'test_zone',
        ];
        $this->rendererPool->expects($this->once())
            ->method('createPriceRender')
            ->with('msrp_price', $this->product, $arguments)
            ->willReturn($priceBoxRender);

        $this->salableResolverMock->expects($this->once())->method('isSalable')->with($this->product)->willReturn(true);

        $result = $this->object->toHtml();

        //assert price wrapper
        $this->assertEquals(
            '<div class="price-box price-final_price" data-role="priceBox" data-product-id="" ' .
            'data-price-box="product-id-">test</div>',
            $result
        );
    }

    public function testRenderMsrpNotRegisteredException()
    {
        $this->logger->expects($this->once())
            ->method('critical');

        $this->priceInfo->expects($this->once())
            ->method('getPrice')
            ->with($this->equalTo('msrp_price'))
            ->will($this->throwException(new \InvalidArgumentException()));

        $this->salableResolverMock->expects($this->once())->method('isSalable')->with($this->product)->willReturn(true);

        $result = $this->object->toHtml();

        //assert price wrapper
        $this->assertStringStartsWith('<div', $result);
        //assert css_selector
        $this->assertMatchesRegularExpression('/[final_price]/', $result);
    }

    public function testRenderAmountMinimal()
    {
        $priceId = 'price_id';
        $html = 'html';

        $this->object->setData('price_id', $priceId);
        $this->product->expects($this->never())->method('getId');

        $amount = $this->getMockForAbstractClass(AmountInterface::class);

        $this->minimalPriceCalculator->expects($this->once())->method('getAmount')
            ->with($this->product)
            ->willReturn($amount);

        $arguments = [
            'zone' => 'test_zone',
            'list_category_page' => true,
            'display_label' => 'As low as',
            'price_id' => $priceId,
            'include_container' => false,
            'skip_adjustments' => true,
        ];

        $amountRender = $this->createPartialMock(Amount::class, ['toHtml']);
        $amountRender->expects($this->once())
            ->method('toHtml')
            ->willReturn($html);

        $this->rendererPool->expects($this->once())
            ->method('createAmountRender')
            ->with($amount, $this->product, $this->price, $arguments)
            ->willReturn($amountRender);

        $this->assertEquals($html, $this->object->renderAmountMinimal());
    }

    /**
     * @dataProvider hasSpecialPriceProvider
     * @param float $regularPrice
     * @param float $finalPrice
     * @param bool $expectedResult
     */
    public function testHasSpecialPrice($regularPrice, $finalPrice, $expectedResult)
    {
        $regularPriceType = $this->createMock(\Magento\Catalog\Pricing\Price\RegularPrice::class);
        $finalPriceType = $this->createMock(\Magento\Catalog\Pricing\Price\FinalPrice::class);
        $regularPriceAmount = $this->getMockForAbstractClass(\Magento\Framework\Pricing\Amount\AmountInterface::class);
        $finalPriceAmount = $this->getMockForAbstractClass(\Magento\Framework\Pricing\Amount\AmountInterface::class);

        $regularPriceAmount->expects($this->once())
            ->method('getValue')
            ->willReturn($regularPrice);
        $finalPriceAmount->expects($this->once())
            ->method('getValue')
            ->willReturn($finalPrice);

        $regularPriceType->expects($this->once())
            ->method('getAmount')
            ->willReturn($regularPriceAmount);
        $finalPriceType->expects($this->once())
            ->method('getAmount')
            ->willReturn($finalPriceAmount);

        $this->priceInfo->expects($this->at(0))
            ->method('getPrice')
            ->with(\Magento\Catalog\Pricing\Price\RegularPrice::PRICE_CODE)
            ->willReturn($regularPriceType);
        $this->priceInfo->expects($this->at(1))
            ->method('getPrice')
            ->with(\Magento\Catalog\Pricing\Price\FinalPrice::PRICE_CODE)
            ->willReturn($finalPriceType);

        $this->assertEquals($expectedResult, $this->object->hasSpecialPrice());
    }

    /**
     * @return array
     */
    public function hasSpecialPriceProvider()
    {
        return [
            [10.0, 20.0, false],
            [20.0, 10.0, true],
            [10.0, 10.0, false]
        ];
    }

    public function testShowMinimalPrice()
    {
        $minimalPrice = 5.0;
        $finalPrice = 10.0;
        $displayMinimalPrice = true;

        $this->minimalPriceCalculator->expects($this->once())->method('getValue')->with($this->product)
            ->willReturn($minimalPrice);

        $finalPriceAmount = $this->getMockForAbstractClass(AmountInterface::class);
        $finalPriceAmount->expects($this->once())
            ->method('getValue')
            ->willReturn($finalPrice);

        $finalPriceType = $this->createMock(FinalPrice::class);
        $finalPriceType->expects($this->once())
            ->method('getAmount')
            ->willReturn($finalPriceAmount);

        $this->priceInfo->expects($this->once())
            ->method('getPrice')
            ->with(FinalPrice::PRICE_CODE)
            ->willReturn($finalPriceType);

        $this->object->setDisplayMinimalPrice($displayMinimalPrice);
        $this->assertTrue($this->object->showMinimalPrice());
    }

    public function testHidePrice()
    {
        $this->product->expects($this->any())
            ->method('getCanShowPrice')
            ->willReturn(false);

        $this->assertEmpty($this->object->toHtml());
    }

    public function testGetCacheKey()
    {
        $result = $this->object->getCacheKey();
        $this->assertStringEndsWith('list-category-page', $result);
    }

    public function testGetCacheKeyInfoContainsDisplayMinimalPrice()
    {
        $this->assertArrayHasKey('display_minimal_price', $this->object->getCacheKeyInfo());
    }

    /**
     * Test when is_product_list flag is not specified
     */
    public function testGetCacheKeyInfoContainsIsProductListFlagByDefault()
    {
        $cacheInfo = $this->object->getCacheKeyInfo();
        self::assertArrayHasKey('is_product_list', $cacheInfo);
        self::assertFalse($cacheInfo['is_product_list']);
    }

    /**
     * Test when is_product_list flag is specified
     *
     * @param bool $flag
     * @dataProvider isProductListDataProvider
     */
    public function testGetCacheKeyInfoContainsIsProductListFlag($flag)
    {
        $this->object->setData('is_product_list', $flag);
        $cacheInfo = $this->object->getCacheKeyInfo();
        self::assertArrayHasKey('is_product_list', $cacheInfo);
        self::assertEquals($flag, $cacheInfo['is_product_list']);
    }

    /**
     * Test when is_product_list flag is not specified
     */
    public function testIsProductListByDefault()
    {
        self::assertFalse($this->object->isProductList());
    }

    /**
     * Test when is_product_list flag is specified
     *
     * @param bool $flag
     * @dataProvider isProductListDataProvider
     */
    public function testIsProductList($flag)
    {
        $this->object->setData('is_product_list', $flag);
        self::assertEquals($flag, $this->object->isProductList());
    }

    /**
     * @return array
     */
    public function isProductListDataProvider()
    {
        return [
            'is_not_product_list' => [false],
            'is_product_list' => [true],
        ];
    }
}
