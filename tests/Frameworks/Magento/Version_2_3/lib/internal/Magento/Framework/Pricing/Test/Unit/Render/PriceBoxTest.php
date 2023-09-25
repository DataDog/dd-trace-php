<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Pricing\Test\Unit\Render;

use Magento\Framework\Pricing\Render\PriceBox;

/**
 * Test class for \Magento\Framework\Pricing\Render\PriceBox
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PriceBoxTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @var \Magento\Framework\TestFramework\Unit\Helper\ObjectManager
     */
    protected $objectManager;

    /**
     * @var PriceBox
     */
    protected $model;

    /**
     * @var \Magento\Framework\View\Element\Template\Context|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $context;

    /**
     * @var \Magento\Framework\Pricing\Render\RendererPool|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $rendererPool;

    /**
     * @var \Magento\Framework\Pricing\SaleableInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $saleable;

    /**
     * @var \Magento\Framework\Pricing\Price\PriceInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $price;

    protected function setUp(): void
    {
        $this->objectManager = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);

        $this->rendererPool = $this->getMockBuilder(\Magento\Framework\Pricing\Render\RendererPool::class)
            ->disableOriginalConstructor()
            ->setMethods(['createAmountRender'])
            ->getMock();

        $layout = $this->createMock(\Magento\Framework\View\LayoutInterface::class);
        $eventManager = $this->createMock(\Magento\Framework\Event\ManagerInterface::class);
        $scopeConfigMock = $this->getMockForAbstractClass(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $cacheState = $this->getMockBuilder(\Magento\Framework\App\Cache\StateInterface::class)
            ->getMockForAbstractClass();
        $storeConfig = $this->getMockBuilder(\Magento\Store\Model\Store\Config::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->context = $this->getMockBuilder(\Magento\Framework\View\Element\Template\Context::class)
            ->setMethods(['getLayout', 'getEventManager', 'getStoreConfig', 'getScopeConfig', 'getCacheState'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->context->expects($this->any())
            ->method('getLayout')
            ->willReturn($layout);
        $this->context->expects($this->any())
            ->method('getEventManager')
            ->willReturn($eventManager);
        $this->context->expects($this->any())
            ->method('getStoreConfig')
            ->willReturn($storeConfig);
        $this->context->expects($this->any())
            ->method('getScopeConfig')
            ->willReturn($scopeConfigMock);
        $this->context->expects($this->any())
            ->method('getCacheState')
            ->willReturn($cacheState);

        $this->saleable = $this->createMock(\Magento\Framework\Pricing\SaleableInterface::class);

        $this->price = $this->createMock(\Magento\Framework\Pricing\Price\PriceInterface::class);

        $this->model = $this->objectManager->getObject(
            \Magento\Framework\Pricing\Render\PriceBox::class,
            [
                'context' => $this->context,
                'saleableItem' => $this->saleable,
                'price' => $this->price,
                'rendererPool' => $this->rendererPool
            ]
        );
    }

    /**
     * @param array $data
     * @param string $priceCode
     * @param array $cssClasses
     * @dataProvider toHtmlDataProvider
     */
    public function testToHtml($data, $priceCode, $cssClasses)
    {
        $this->price->expects($this->once())
            ->method('getPriceCode')
            ->willReturn($priceCode);

        $priceBox = $this->objectManager->getObject(
            \Magento\Framework\Pricing\Render\PriceBox::class,
            [
                'context' => $this->context,
                'saleableItem' => $this->saleable,
                'price' => $this->price,
                'rendererPool' => $this->rendererPool,
                'data' => $data
            ]
        );
        $priceBox->toHtml();
        $this->assertEquals($cssClasses, $priceBox->getData('css_classes'));
    }

    /**
     * @return array
     */
    public function toHtmlDataProvider()
    {
        return [
            [
                'data' => [],
                'price_code' => 'test_price',
                'css_classes' => 'price-test_price',
            ],
            [
                'data' => ['css_classes' => 'some_css_class'],
                'price_code' => 'test_price',
                'css_classes' => 'some_css_class price-test_price'
            ]];
    }

    public function testGetSaleableItem()
    {
        $this->assertEquals($this->saleable, $this->model->getSaleableItem());
    }

    public function testGetPrice()
    {
        $this->assertEquals($this->price, $this->model->getPrice());
    }

    public function testGetPriceType()
    {
        $priceCode = 'test_price';

        $price = $this->createMock(\Magento\Framework\Pricing\Price\PriceInterface::class);

        $priceInfo = $this->createMock(\Magento\Framework\Pricing\PriceInfo\Base::class);
        $priceInfo->expects($this->once())
            ->method('getPrice')
            ->with($priceCode)
            ->willReturn($price);

        $this->saleable->expects($this->once())
            ->method('getPriceInfo')
            ->willReturn($priceInfo);

        $this->assertEquals($price, $this->model->getPriceType($priceCode));
    }

    public function testRenderAmount()
    {
        $amount = $this->createMock(\Magento\Framework\Pricing\Amount\AmountInterface::class);
        $arguments = [];
        $resultHtml = 'result_html';

        $amountRender = $this->getMockBuilder(\Magento\Framework\Pricing\Render\Amount::class)
            ->disableOriginalConstructor()
            ->setMethods(['toHtml'])
            ->getMock();
        $amountRender->expects($this->once())
            ->method('toHtml')
            ->willReturn($resultHtml);

        $this->rendererPool->expects($this->once())
            ->method('createAmountRender')
            ->with($amount, $this->saleable, $this->price, $arguments)
            ->willReturn($amountRender);

        $this->assertEquals($resultHtml, $this->model->renderAmount($amount, $arguments));
    }

    public function testGetPriceIdHasDataPriceId()
    {
        $priceId = 'data_price_id';
        $this->model->setData('price_id', $priceId);
        $this->assertEquals($priceId, $this->model->getPriceId());
    }

    /**
     * @dataProvider getPriceIdProvider
     * @param string $prefix
     * @param string $suffix
     * @param string $defaultPrefix
     * @param string $defaultSuffix
     */
    public function testGetPriceId($prefix, $suffix, $defaultPrefix, $defaultSuffix)
    {
        $priceId = 'price_id';
        $this->saleable->expects($this->once())
            ->method('getId')
            ->willReturn($priceId);

        if (!empty($prefix)) {
            $this->model->setData('price_id_prefix', $prefix);
            $expectedPriceId = $prefix . $priceId;
        } else {
            $expectedPriceId = $defaultPrefix . $priceId;
        }
        if (!empty($suffix)) {
            $this->model->setData('price_id_suffix', $suffix);
            $expectedPriceId = $expectedPriceId . $suffix;
        } else {
            $expectedPriceId = $expectedPriceId . $defaultSuffix;
        }

        $this->assertEquals($expectedPriceId, $this->model->getPriceId($defaultPrefix, $defaultSuffix));
    }

    /**
     * @return array
     */
    public function getPriceIdProvider()
    {
        return [
            ['prefix', 'suffix', 'default_prefix', 'default_suffix'],
            ['prefix', 'suffix', 'default_prefix', ''],
            ['prefix', 'suffix', '', 'default_suffix'],
            ['prefix', '', 'default_prefix', 'default_suffix'],
            ['', 'suffix', 'default_prefix', 'default_suffix'],
            ['', '', 'default_prefix', 'default_suffix'],
            ['prefix', 'suffix', '', '']
        ];
    }

    public function testGetRendererPool()
    {
        $this->assertEquals($this->rendererPool, $this->model->getRendererPool());
    }
}
