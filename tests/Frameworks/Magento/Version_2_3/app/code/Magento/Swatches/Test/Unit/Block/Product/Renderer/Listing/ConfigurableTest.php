<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Swatches\Test\Unit\Block\Product\Renderer\Listing;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Swatches\Block\Product\Renderer\Configurable;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class ConfigurableTest extends \PHPUnit\Framework\TestCase
{
    /** @var Configurable */
    private $configurable;

    /** @var \Magento\Framework\Stdlib\ArrayUtils|\PHPUnit\Framework\MockObject\MockObject */
    private $arrayUtils;

    /** @var \Magento\Framework\Json\EncoderInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $jsonEncoder;

    /** @var \Magento\ConfigurableProduct\Helper\Data|\PHPUnit\Framework\MockObject\MockObject */
    private $helper;

    /** @var \Magento\Swatches\Helper\Data|\PHPUnit\Framework\MockObject\MockObject */
    private $swatchHelper;

    /** @var \Magento\Swatches\Helper\Media|\PHPUnit\Framework\MockObject\MockObject */
    private $swatchMediaHelper;

    /** @var \Magento\Catalog\Helper\Product|\PHPUnit\Framework\MockObject\MockObject */
    private $catalogProduct;

    /** @var \Magento\Customer\Helper\Session\CurrentCustomer|\PHPUnit\Framework\MockObject\MockObject */
    private $currentCustomer;

    /** @var \Magento\Framework\Pricing\PriceCurrencyInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $priceCurrency;

    /** @var \Magento\ConfigurableProduct\Model\ConfigurableAttributeData|\PHPUnit\Framework\MockObject\MockObject */
    private $configurableAttributeData;

    /** @var \Magento\Catalog\Model\Product|\PHPUnit\Framework\MockObject\MockObject */
    private $product;

    /** @var \Magento\Catalog\Model\Product\Type\AbstractType|\PHPUnit\Framework\MockObject\MockObject */
    private $typeInstance;

    /** @var \Magento\Framework\App\Config\ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfig;

    /** @var \Magento\Catalog\Helper\Image|\PHPUnit\Framework\MockObject\MockObject */
    private $imageHelper;

    /** @var \Magento\Catalog\Model\Product\Image\UrlBuilder|\PHPUnit\Framework\MockObject\MockObject  */
    private $imageUrlBuilder;

    /** @var \PHPUnit\Framework\MockObject\MockObject */
    private $variationPricesMock;

    protected function setUp(): void
    {
        $this->arrayUtils = $this->createMock(\Magento\Framework\Stdlib\ArrayUtils::class);
        $this->jsonEncoder = $this->createMock(\Magento\Framework\Json\EncoderInterface::class);
        $this->helper = $this->createMock(\Magento\ConfigurableProduct\Helper\Data::class);
        $this->swatchHelper = $this->createMock(\Magento\Swatches\Helper\Data::class);
        $this->swatchMediaHelper = $this->createMock(\Magento\Swatches\Helper\Media::class);
        $this->catalogProduct = $this->createMock(\Magento\Catalog\Helper\Product::class);
        $this->currentCustomer = $this->createMock(\Magento\Customer\Helper\Session\CurrentCustomer::class);
        $this->priceCurrency = $this->createMock(\Magento\Framework\Pricing\PriceCurrencyInterface::class);
        $this->configurableAttributeData = $this->createMock(
            \Magento\ConfigurableProduct\Model\ConfigurableAttributeData::class
        );
        $this->product = $this->createMock(\Magento\Catalog\Model\Product::class);
        $this->typeInstance = $this->createMock(\Magento\Catalog\Model\Product\Type\AbstractType::class);
        $this->scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $this->imageHelper = $this->createMock(\Magento\Catalog\Helper\Image::class);
        $this->imageUrlBuilder = $this->createMock(\Magento\Catalog\Model\Product\Image\UrlBuilder::class);
        $this->variationPricesMock = $this->createMock(
            \Magento\ConfigurableProduct\Model\Product\Type\Configurable\Variations\Prices::class
        );

        $objectManagerHelper = new \Magento\Framework\TestFramework\Unit\Helper\ObjectManager($this);
        $this->configurable = $objectManagerHelper->getObject(
            \Magento\Swatches\Block\Product\Renderer\Listing\Configurable::class,
            [
                'scopeConfig' => $this->scopeConfig,
                'imageHelper' => $this->imageHelper,
                'imageUrlBuilder' => $this->imageUrlBuilder,
                'arrayUtils' => $this->arrayUtils,
                'jsonEncoder' => $this->jsonEncoder,
                'helper' => $this->helper,
                'swatchHelper' => $this->swatchHelper,
                'swatchMediaHelper' => $this->swatchMediaHelper,
                'catalogProduct' => $this->catalogProduct,
                'currentCustomer' => $this->currentCustomer,
                'priceCurrency' => $this->priceCurrency,
                'configurableAttributeData' => $this->configurableAttributeData,
                'data' => [],
                'variationPrices' => $this->variationPricesMock
            ]
        );
    }

    /**
     * @covers Magento\Swatches\Block\Product\Renderer\Listing\Configurable::getSwatchAttributesData
     */
    public function testGetJsonSwatchConfigWithoutSwatches()
    {
        $this->prepareGetJsonSwatchConfig();
        $this->configurable->setProduct($this->product);
        $this->swatchHelper->expects($this->once())->method('getSwatchAttributesAsArray')
            ->with($this->product)
            ->willReturn([]);
        $this->swatchHelper->expects($this->once())->method('getSwatchesByOptionsId')
            ->willReturn([]);
        $this->jsonEncoder->expects($this->once())->method('encode')->with([]);
        $this->configurable->getJsonSwatchConfig();
    }

    /**
     * @covers Magento\Swatches\Block\Product\Renderer\Listing\Configurable::getSwatchAttributesData
     */
    public function testGetJsonSwatchNotUsedInProductListing()
    {
        $this->prepareGetJsonSwatchConfig();
        $this->configurable->setProduct($this->product);
        $this->swatchHelper->expects($this->once())->method('getSwatchAttributesAsArray')
            ->with($this->product)
            ->willReturn(
                [
                    1 => [
                        'options' => [1 => 'testA', 3 => 'testB'],
                        'use_product_image_for_swatch' => true,
                        'used_in_product_listing' => false,
                        'attribute_code' => 'code',
                    ],
                ]
            );
        $this->swatchHelper->expects($this->once())->method('getSwatchesByOptionsId')
            ->willReturn([]);
        $this->jsonEncoder->expects($this->once())->method('encode')->with([]);
        $this->configurable->getJsonSwatchConfig();
    }

    /**
     * @covers Magento\Swatches\Block\Product\Renderer\Listing\Configurable::getSwatchAttributesData
     */
    public function testGetJsonSwatchUsedInProductListing()
    {
        $products = [
            1 => 'testA',
            3 => 'testB'
        ];
        $expected =
            [
                'type' => null,
                'value' => 'hello',
                'label' => $products[3]
            ];
        $this->prepareGetJsonSwatchConfig();
        $this->configurable->setProduct($this->product);
        $this->swatchHelper->expects($this->once())->method('getSwatchAttributesAsArray')
            ->with($this->product)
            ->willReturn(
                [
                    1 => [
                        'options' => $products,
                        'use_product_image_for_swatch' => true,
                        'used_in_product_listing' => true,
                        'attribute_code' => 'code',
                    ],
                ]
            );
        $this->swatchHelper->expects($this->once())->method('getSwatchesByOptionsId')
            ->with([1, 3])
            ->willReturn([3 => ['type' => $expected['type'], 'value' => $expected['value']]]);
        $this->jsonEncoder->expects($this->once())->method('encode');
        $this->configurable->getJsonSwatchConfig();
    }

    private function prepareGetJsonSwatchConfig()
    {
        $product1 = $this->createMock(\Magento\Catalog\Model\Product::class);
        $product1->expects($this->any())->method('isSaleable')->willReturn(true);
        $product1->expects($this->atLeastOnce())->method('getStatus')->willReturn(Status::STATUS_ENABLED);
        $product1->expects($this->any())->method('getData')->with('code')->willReturn(1);

        $product2 = $this->createMock(\Magento\Catalog\Model\Product::class);
        $product2->expects($this->any())->method('isSaleable')->willReturn(true);
        $product2->expects($this->atLeastOnce())->method('getStatus')->willReturn(Status::STATUS_ENABLED);
        $product2->expects($this->any())->method('getData')->with('code')->willReturn(3);

        $simpleProducts = [$product1, $product2];
        $configurableType = $this->createMock(\Magento\ConfigurableProduct\Model\Product\Type\Configurable::class);
        $configurableType->expects($this->atLeastOnce())->method('getUsedProducts')->with($this->product, null)
            ->willReturn($simpleProducts);
        $this->product->expects($this->any())->method('getTypeInstance')->willReturn($configurableType);

        $productAttribute1 = $this->createMock(\Magento\Eav\Model\Entity\Attribute\AbstractAttribute::class);
        $productAttribute1->expects($this->any())->method('getId')->willReturn(1);
        $productAttribute1->expects($this->any())->method('getAttributeCode')->willReturn('code');

        $attribute1 = $this->createPartialMock(
            \Magento\ConfigurableProduct\Model\Product\Type\Configurable\Attribute::class,
            ['getProductAttribute']
        );
        $attribute1->expects($this->any())->method('getProductAttribute')->willReturn($productAttribute1);

        $this->helper->expects($this->any())->method('getAllowAttributes')->with($this->product)
            ->willReturn([$attribute1]);
    }

    public function testGetPricesJson()
    {
        $expectedPrices = [
            'oldPrice' => [
                'amount' => 10,
            ],
            'basePrice' => [
                'amount' => 15,
            ],
            'finalPrice' => [
                'amount' => 20,
            ],
        ];

        $priceInfoMock = $this->createMock(\Magento\Framework\Pricing\PriceInfo\Base::class);
        $this->configurable->setProduct($this->product);
        $this->product->expects($this->once())->method('getPriceInfo')->willReturn($priceInfoMock);
        $this->variationPricesMock->expects($this->once())
            ->method('getFormattedPrices')
            ->with($priceInfoMock)
            ->willReturn($expectedPrices);

        $this->jsonEncoder->expects($this->once())->method('encode')->with($expectedPrices);
        $this->configurable->getPricesJson();
    }
}
